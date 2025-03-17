<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php 
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Client/Client.php
 */

declare(strict_types=1);

namespace Mcp\Client;

use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\SseTransport;
use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Types\JsonRpcMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class Client
 *
 * Main client class for MCP communication.
 *
 * The client can connect to a server via STDIO or SSE, initialize a session,
 * and start a receive loop to process incoming messages.
 */
class Client {
    /** @var ClientSession|null */
    private ?ClientSession $session = null;

    /** @var StdioTransport|null */
    private ?StdioTransport $transport = null;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var bool */
    private bool $isRunning = false;

    /**
     * Client constructor.
     *
     * @param LoggerInterface|null $logger PSR-3 compliant logger.
     */
    public function __construct(?LoggerInterface $logger = null) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Connect to an MCP server using either STDIO or SSE.
     *
     * If commandOrUrl is an HTTP(S) URL, it uses SSE transport.
     * Otherwise, it assumes it's a command and uses STDIO transport.
     *
     * @param string      $commandOrUrl The command to execute or the HTTP(S) URL.
     * @param array       $args         Arguments for the command (if using STDIO transport).
     * @param array|null  $env          Environment variables for the command (if using STDIO transport).
     * @param float|null  $readTimeout  Timeout for reading messages.
     *
     * @throws InvalidArgumentException If the command or URL is invalid.
     * @throws RuntimeException         If the connection fails.
     *
     * @return ClientSession The initialized client session.
     */
    public function connect(
        string $commandOrUrl,
        array $args = [],
        ?array $env = null,
        ?float $readTimeout = null
    ): ClientSession {
        $urlParts = parse_url($commandOrUrl);

        try {
            if (isset($urlParts['scheme']) && in_array(strtolower($urlParts['scheme']), ['http', 'https'], true)) {
                // Use SSE transport for HTTP(S) URLs
                $this->logger->info("Connecting to SSE endpoint: {$commandOrUrl}");
                $transport = new SseTransport(
                    url: $commandOrUrl,
                    headers: [],            // Add custom headers if needed
                    timeout: 5.0,           // Connection timeout
                    sseReadTimeout: 300.0,  // SSE read timeout
                    logger: $this->logger
                );
            } else {
                // Use STDIO transport for commands
                $this->logger->info("Starting process: {$commandOrUrl}");
                $params = new StdioServerParameters($commandOrUrl, $args, $env);
                $transport = new StdioTransport($params, $this->logger);
                $this->transport = $transport;
            }

            // Establish connection and retrieve read/write streams
            [$readStream, $writeStream] = $transport->connect();

            // Initialize the client session with the obtained streams
            $this->session = new ClientSession(
                readStream: $readStream,
                writeStream: $writeStream,
                readTimeout: $readTimeout
            );

            // Initialize the session (e.g., perform handshake if necessary)
            $this->session->initialize();
            $this->logger->info('Session initialized successfully');

            // Currently only synchronous is supported, but this lays the groundwork for async to be implemented in the future
            //$this->startReceiveLoop();

            return $this->session;
        } catch (\Exception $e) {
            $this->logger->error("Connection failed: {$e->getMessage()}");
            throw new RuntimeException("Connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Process incoming messages in a loop.
     *
     * Similar to the Python `receive_loop()` which iterates over `session.incoming_messages`.
     *
     * @return void
     */
    private function receiveLoop(): void {
        if (!$this->session) {
            throw new RuntimeException('No active session to receive messages');
        }

        $this->logger->info('Starting receive loop');
        $this->isRunning = true;

        while ($this->isRunning && $this->session->isInitialized()) {
            try {
                /** @var JsonRpcMessage|null $message */
                $message = $this->session->receiveMessage();

                if ($message === null) {
                    // No message available, sleep briefly to prevent busy waiting
                    usleep(10000); // 10 milliseconds
                    continue;
                }

                if ($message instanceof \Exception) {
                    $this->logger->error("Error received: {$message->getMessage()}");
                    continue;
                }

                if ($message instanceof JsonRpcMessage) {
                    $this->logger->info('Received message from server: ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                    // Additional processing can be performed here if necessary
                }
            } catch (\Exception $e) {
                $this->logger->error("Exception in receive loop: {$e->getMessage()}");
                // Decide whether to continue the loop or break based on the exception type
                continue;
            }
        }

        $this->logger->info('Receive loop terminated');
    }

    /**
     * Start the message processing loop in a non-blocking way if possible, else inline.
     *
     * @return void
     */
    private function startReceiveLoop(): void {
        if (!$this->session) {
            throw new RuntimeException('No active session to start receive loop');
        }

        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Failed to start receive loop: fork failed');
            }
            if ($pid === 0) {
                // Child process: handle incoming messages
                try {
                    $this->receiveLoop();
                } finally {
                    exit(0);
                }
            }
            // Parent process continues execution
            $this->logger->info("Receive loop started in child process (PID: $pid)");
        } else {
            // Run in the same process if pcntl_fork is not available
            $this->logger->warning('pcntl_fork not available. Running receive loop in the main process.');
            set_time_limit(0); // Prevent script from timing out
            $this->receiveLoop();
        }
    }

    /**
     * Close the client connection gracefully.
     *
     * @return void
     */
    public function close(): void {
        $this->isRunning = false;
        if ($this->session) {
            $this->session->close();
            $this->logger->info('Session closed successfully');
            $this->session = null;
        }
        if ($this->transport) {
            $this->transport->close();
            $this->logger->info('Transport closed successfully');
            $this->transport = null;
        }
    }
}
