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
 * Filename: Client/Transport/SseTransport.php
 */

declare(strict_types=1);

namespace Mcp\Client\Transport;

use Mcp\Types\JsonRpcMessage;
use Mcp\Shared\MemoryStream;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;
use Exception; // Import the global Exception class
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\RequestId;
use Mcp\Types\JsonRpcErrorObject;

/**
 * Class SseTransport
 *
 * Handles SSE (Server-Sent Events) based communication with an MCP server.
 */
class SseTransport {
    /** @var resource|null */
    private $eventStream = null;

    /** @var resource|null */
    private $curlHandle = null;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var string|null */
    private ?string $endpoint = null;

    /** @var array<string> */
    private array $headers = [];

    /**
     * SseTransport constructor.
     *
     * @param string             $url            The SSE endpoint URL.
     * @param array              $headers        Optional HTTP headers.
     * @param float              $timeout        Connection timeout in seconds.
     * @param float              $sseReadTimeout SSE read timeout in seconds.
     * @param LoggerInterface|null $logger      PSR-3 compliant logger.
     *
     * @throws InvalidArgumentException If the URL is empty.
     */
    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly float $timeout = 5.0,
        private readonly float $sseReadTimeout = 300.0,
        ?LoggerInterface $logger = null,
    ) {
        if (empty($url)) {
            throw new InvalidArgumentException('URL cannot be empty');
        }
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Opens the connection to the SSE server.
     *
     * @return array{MemoryStream, MemoryStream} Tuple of read and write streams.
     *
     * @throws RuntimeException If the connection fails.
     */
    public function connect(): array {
        $this->logger->info('Connecting to SSE endpoint: ' . $this->removeRequestParams($this->url));

        // Initialize cURL for SSE
        $this->curlHandle = curl_init();
        if ($this->curlHandle === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($this->curlHandle, [
            CURLOPT_URL => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: text/event-stream',
                'Cache-Control: no-cache',
            ], $this->formatHeaders($this->headers)),
            CURLOPT_TIMEOUT => $this->sseReadTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => [$this, 'handleCurlWrite'],
        ]);

        // Initialize eventStream as a temporary in-memory stream
        $this->eventStream = fopen('php://temp', 'r+');
        if ($this->eventStream === false) {
            throw new RuntimeException('Failed to create event stream buffer');
        }

        // Execute cURL in non-blocking mode
        curl_setopt($this->curlHandle, CURLOPT_TIMEOUT_MS, (int)($this->sseReadTimeout * 1000));
        $exec = curl_exec($this->curlHandle);
        if ($exec === false) {
            $error = curl_error($this->curlHandle);
            throw new RuntimeException("cURL error while connecting: $error");
        }

        $this->logger->info('Connected to SSE endpoint successfully.');

        // Initialize read and write streams
        $readStream = new class($this->eventStream, $this->logger) extends MemoryStream {
            private $pipe;
            private LoggerInterface $logger;

            public function __construct($pipe, LoggerInterface $logger) {
                $this->pipe = $pipe;
                $this->logger = $logger;
                // Removed parent::__construct();
            }

            /**
             * Receive a JsonRpcMessage from the server.
             *
             * @return JsonRpcMessage|Exception|null The received message, an exception, or null if no message is available.
             */
            public function receive(): mixed {
                while (($line = fgets($this->pipe)) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    if (str_starts_with($line, 'event:')) {
                        $eventName = trim(substr($line, 6));
                        continue;
                    }

                    if (str_starts_with($line, 'data:')) {
                        $data = trim(substr($line, 5));
                        try {
                            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                            $jsonRpcMessage = $this->instantiateJsonRpcMessage($decoded);
                            $this->logger->debug('Received JsonRpcMessage: ' . json_encode($jsonRpcMessage, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                            return $jsonRpcMessage;
                        } catch (\JsonException $e) {
                            $this->logger->error('JSON parse error: ' . $e->getMessage());
                            return $e;
                        } catch (InvalidArgumentException $e) {
                            $this->logger->error('Invalid JsonRpcMessage: ' . $e->getMessage());
                            return $e;
                        }
                    }
                }

                if (feof($this->pipe)) {
                    $this->logger->warning('SSE server has closed the connection.');
                    return new RuntimeException('SSE server closed the connection.');
                }

                return null;
            }

            /**
             * Instantiate a JsonRpcMessage from decoded data.
             *
             * @param array $data The decoded JSON data.
             *
             * @return JsonRpcMessage The instantiated JsonRpcMessage object.
             *
             * @throws InvalidArgumentException If the message structure is invalid.
             */
            private function instantiateJsonRpcMessage(array $data): JsonRpcMessage {
                if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
                    throw new InvalidArgumentException('Invalid JSON-RPC version.');
                }

                if (isset($data['method'])) {
                    // It's a Request or Notification
                    if (isset($data['id'])) {
                        // It's a Request
                        return new JsonRpcMessage(new JSONRPCRequest(
                            jsonrpc: '2.0',
                            id: new RequestId($data['id']),
                            method: $data['method'],
                            params: $data['params'] ?? null
                        ));
                    } else {
                        // It's a Notification
                        return new JsonRpcMessage(new JSONRPCNotification(
                            jsonrpc: '2.0',
                            method: $data['method'],
                            params: $data['params'] ?? null
                        ));
                    }
                } elseif (isset($data['result']) || isset($data['error'])) {
                    // It's a Response or Error
                    if (isset($data['error'])) {
                        // It's an Error
                        $errorData = $data['error'];
                        return new JsonRpcMessage(new JSONRPCError(
                            jsonrpc: '2.0',
                            id: isset($data['id']) ? new RequestId($data['id']) : null,
                            error: new JsonRpcErrorObject(
                                code: $errorData['code'],
                                message: $errorData['message'],
                                data: $errorData['data'] ?? null
                            )
                        ));
                    } else {
                        // It's a Response
                        return new JsonRpcMessage(new JSONRPCResponse(
                            jsonrpc: '2.0',
                            id: isset($data['id']) ? new RequestId($data['id']) : null,
                            result: $data['result']
                        ));
                    }
                } else {
                    throw new InvalidArgumentException('Invalid JSON-RPC message structure.');
                }
            }
        };

        $writeStream = new class($this->curlHandle, $this->logger) extends MemoryStream {
            private $curlHandle;
            private LoggerInterface $logger;

            public function __construct($curlHandle, LoggerInterface $logger) {
                $this->curlHandle = $curlHandle;
                $this->logger = $logger;
                // Removed parent::__construct();
            }

            /**
             * Send a JsonRpcMessage or Exception to the server via SSE.
             *
             * @param JsonRpcMessage|Exception $message The JSON-RPC message or exception to send.
             *
             * @return void
             *
             * @throws InvalidArgumentException If the message is not a JsonRpcMessage.
             * @throws RuntimeException If sending the message fails.
             */
            public function send(mixed $message): void {
                if (!$message instanceof JsonRpcMessage) {
                    throw new InvalidArgumentException('Only JsonRpcMessage instances can be sent.');
                }

                $innerMessage = $message->message;

                if ($innerMessage instanceof \Mcp\Types\JSONRPCRequest ||
                    $innerMessage instanceof \Mcp\Types\JSONRPCNotification) {
                    $payload = [
                        'jsonrpc' => '2.0',
                        'method' => $innerMessage->method,
                        'params' => $innerMessage->params ?? []
                    ];

                    if ($innerMessage instanceof \Mcp\Types\JSONRPCRequest) {
                        $payload['id'] = (string)$innerMessage->id->value;
                    }
                } elseif ($innerMessage instanceof \Mcp\Types\JSONRPCResponse ||
                          $innerMessage instanceof \Mcp\Types\JSONRPCError) {
                    $payload = [
                        'jsonrpc' => '2.0',
                        'id' => $innerMessage->id ? (string)$innerMessage->id->value : null
                    ];

                    if ($innerMessage instanceof \Mcp\Types\JSONRPCResponse) {
                        $payload['result'] = $innerMessage->result;
                    } elseif ($innerMessage instanceof \Mcp\Types\JSONRPCError) {
                        $payload['error'] = [
                            'code' => $innerMessage->error->code,
                            'message' => $innerMessage->error->message,
                            'data' => $innerMessage->error->data
                        ];
                    }
                } else {
                    throw new InvalidArgumentException('Unsupported JsonRpcMessage variant.');
                }

                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    throw new RuntimeException('Failed to encode JsonRpcMessage to JSON: ' . json_last_error_msg());
                }

                $json .= "\n"; // Append newline as message delimiter.

                // Send the message via SSE by posting to the endpoint
                $ch = curl_init();
                if ($ch === false) {
                    throw new RuntimeException('Failed to initialize cURL for sending message.');
                }

                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->getEndpoint(),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => array_merge([
                        'Content-Type: application/json',
                    ], $this->getFormattedHeaders()),
                    CURLOPT_TIMEOUT => 10.0,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($response === false) {
                    $this->logger->error('cURL error while sending message: ' . $curlError);
                    throw new RuntimeException("cURL error while sending message: $curlError");
                }

                if ($httpCode < 200 || $httpCode >= 300) {
                    $this->logger->error("HTTP error while sending message: $httpCode - Response: $response");
                    throw new RuntimeException("HTTP error while sending message: $httpCode");
                }

                $this->logger->debug('Sent JsonRpcMessage: ' . $json);
            }
        };

        return [$readStream, $writeStream];
    }

    /**
     * Handles writing data received from cURL to the eventStream.
     *
     * @param resource $ch     The cURL handle.
     * @param string   $data   The data chunk received.
     * @param int      $length The length of the data.
     *
     * @return int The number of bytes handled.
     */
    public function handleCurlWrite($ch, string $data, int $length): int {
        if ($this->eventStream !== null && is_resource($this->eventStream)) {
            fwrite($this->eventStream, $data);
        }
        return $length;
    }

    /**
     * Retrieves the formatted HTTP headers.
     *
     * @return array The formatted HTTP headers.
     */
    public function getFormattedHeaders(): array {
        return $this->formatHeaders($this->headers);
    }

    /**
     * Retrieves the current endpoint URL.
     *
     * @return string|null The endpoint URL or null if not set.
     */
    public function getEndpoint(): ?string {
        return $this->endpoint;
    }

    /**
     * Formats headers into the "Key: Value" format.
     *
     * @param array $headers Associative array of headers.
     *
     * @return array The formatted headers.
     */
    private function formatHeaders(array $headers): array {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "$name: $value";
        }
        return $formatted;
    }

    /**
     * Removes query parameters from the URL for logging purposes.
     *
     * @param string $url The original URL.
     *
     * @return string The URL without query parameters.
     */
    private function removeRequestParams(string $url): string {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        return "{$scheme}://{$host}{$port}{$path}";
    }

    /**
     * Sets the endpoint URL after receiving the 'endpoint' event.
     *
     * @param string $endpointUrl The endpoint URL received from the server.
     *
     * @return void
     *
     * @throws InvalidArgumentException If the endpoint origin does not match the connection origin.
     */
    public function setEndpoint(string $endpointUrl): void {
        $baseUrlParts = parse_url($this->url);
        $endpointParts = parse_url($endpointUrl);

        if (!$baseUrlParts || !$endpointParts) {
            throw new RuntimeException('Invalid URL format for endpoint.');
        }

        // Validate that the endpoint has the same origin as the base URL
        $isSameOrigin = (
            ($baseUrlParts['scheme'] ?? '') === ($endpointParts['scheme'] ?? '') &&
            ($baseUrlParts['host'] ?? '') === ($endpointParts['host'] ?? '') &&
            (isset($baseUrlParts['port']) ? (int)$baseUrlParts['port'] : 0) === (isset($endpointParts['port']) ? (int)$endpointParts['port'] : 0)
        );

        if (!$isSameOrigin) {
            throw new InvalidArgumentException('Endpoint origin does not match connection origin: ' . $endpointUrl);
        }

        $this->endpoint = $endpointUrl;
        $this->logger->info('Endpoint URL set: ' . $endpointUrl);
    }
}