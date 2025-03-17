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
 * Filename: Server/Transport/WebSocketServerTransport.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport;

use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Shared\McpError;
use Mcp\Shared\ErrorData;
use Mcp\Shared\BaseSession;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\RequestParams;
use Mcp\Types\NotificationParams;
use Mcp\Types\Result;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\WebSocket\WsServerInterface;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class WebSocketServerTransport
 *
 * WebSocket server transport for MCP.
 *
 * Utilizes Ratchet for WebSocket handling. OnMessage parses incoming JSON-RPC messages,
 * converts them to typed objects, and passes them to the session.
 * writeMessage() broadcasts messages to all connected clients.
 */
class WebSocketServerTransport implements Transport, MessageComponentInterface, WsServerInterface {
    /** @var array<string, ConnectionInterface> */
    private array $connections = [];
    
    /** @var BaseSession|null */
    private ?BaseSession $session = null;
    
    /** @var bool */
    private bool $isStarted = false;
    
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * WebSocketServerTransport constructor.
     *
     * @param LoggerInterface|null $logger PSR-3 compliant logger.
     */
    public function __construct(
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Starts the WebSocket transport.
     *
     * @throws RuntimeException If the transport is already started or if no session is attached.
     *
     * @return void
     */
    public function start(): void {
        if ($this->isStarted) {
            throw new RuntimeException('Transport already started');
        }

        if ($this->session === null) {
            throw new RuntimeException('No session attached to transport');
        }

        $this->isStarted = true;
        $this->logger->debug('WebSocket transport started');
    }

    /**
     * Stops the WebSocket transport and closes all connections.
     *
     * @return void
     */
    public function stop(): void {
        if (!$this->isStarted) {
            return;
        }

        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
        $this->isStarted = false;
        $this->logger->debug('WebSocket transport stopped');
    }

    /**
     * Attaches a session to the transport.
     *
     * @param BaseSession $session The session to attach.
     *
     * @return void
     */
    public function attachSession(BaseSession $session): void {
        $this->session = $session;
        $this->logger->debug('Session attached to WebSocket transport');
    }

    /**
     * Handles new WebSocket connections.
     *
     * @param ConnectionInterface $conn The connection interface.
     *
     * @return void
     */
    public function onOpen(ConnectionInterface $conn): void {
        $connId = spl_object_hash($conn);
        $this->connections[$connId] = $conn;

        $this->logger->debug("New WebSocket connection established: $connId");

        if ($conn instanceof \Ratchet\WebSocket\WsConnection) {
            $conn->setSubProtocol('mcp');
        }
    }

    /**
     * Handles incoming messages from WebSocket clients.
     *
     * @param ConnectionInterface $from The connection interface.
     * @param string              $msg  The incoming message.
     *
     * @return void
     */
    public function onMessage(ConnectionInterface $from, $msg): void {
        try {
            $data = json_decode($msg, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->sendError($from, -32700, 'Parse error: ' . $e->getMessage());
            return;
        }

        // Validate 'jsonrpc' field
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            $this->sendError($from, -32600, 'Invalid Request: jsonrpc version must be "2.0"');
            return;
        }

        // Determine message type
        $hasMethod = array_key_exists('method', $data);
        $hasId = array_key_exists('id', $data);
        $hasResult = array_key_exists('result', $data);
        $hasError = array_key_exists('error', $data);

        $id = null;
        if ($hasId) {
            $id = new RequestId($data['id']);
        }

        try {
            $message = $this->buildMessage($data, $hasMethod, $hasId, $hasResult, $hasError, $id);

            if ($this->session !== null) {
                $this->session->handleIncomingMessage($message);
            }
        } catch (McpError $e) {
            // Send error response
            $error = $e->error;
            $errorMessage = [
                'jsonrpc' => '2.0',
                'id' => $id ? $id->toString() : null,
                'error' => [
                    'code' => $error->code,
                    'message' => $error->message,
                    'data' => $error->data,
                ],
            ];
            $from->send(json_encode($errorMessage, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\Exception $e) {
            $this->sendError($from, -32700, 'Parse error: ' . $e->getMessage(), $id);
        }
    }

    /**
     * Handles closed WebSocket connections.
     *
     * @param ConnectionInterface $conn The connection interface.
     *
     * @return void
     */
    public function onClose(ConnectionInterface $conn): void {
        $connId = spl_object_hash($conn);
        unset($this->connections[$connId]);
        $this->logger->debug("WebSocket connection closed: $connId");
    }

    /**
     * Handles errors on WebSocket connections.
     *
     * @param ConnectionInterface $conn The connection interface.
     * @param \Exception           $e    The exception.
     *
     * @return void
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void {
        $connId = spl_object_hash($conn);
        $this->logger->error("Error on WebSocket connection $connId: " . $e->getMessage());
        $conn->close();
    }

    /**
     * Writes a JSON-RPC message to all connected WebSocket clients.
     *
     * @param JsonRpcMessage $message The JSON-RPC message to send.
     *
     * @throws RuntimeException If the transport is not started.
     *
     * @return void
     */
    public function writeMessage(JsonRpcMessage $message): void {
        if (!$this->isStarted) {
            throw new RuntimeException('Transport not started');
        }

        $innerMessage = $message->message;

        // Serialize the message based on its variant
        if ($innerMessage instanceof \Mcp\Types\JSONRPCRequest) {
            $payload = [
                'jsonrpc' => '2.0',
                'id' => $innerMessage->id->toString(),
                'method' => $innerMessage->method,
                'params' => $this->serializeParams($innerMessage->params),
            ];
        } elseif ($innerMessage instanceof \Mcp\Types\JSONRPCNotification) {
            $payload = [
                'jsonrpc' => '2.0',
                'method' => $innerMessage->method,
                'params' => $this->serializeParams($innerMessage->params),
            ];
        } elseif ($innerMessage instanceof \Mcp\Types\JSONRPCResponse) {
            $payload = [
                'jsonrpc' => '2.0',
                'id' => $innerMessage->id->toString(),
                'result' => $this->serializeResult($innerMessage->result),
            ];
        } elseif ($innerMessage instanceof \Mcp\Types\JSONRPCError) {
            $payload = [
                'jsonrpc' => '2.0',
                'id' => $innerMessage->id ? $innerMessage->id->toString() : null,
                'error' => [
                    'code' => $innerMessage->error->code,
                    'message' => $innerMessage->error->message,
                    'data' => $innerMessage->error->data,
                ],
            ];
        } else {
            throw new RuntimeException('Unsupported JsonRpcMessage variant');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode message as JSON: ' . json_last_error_msg());
        }

        foreach ($this->connections as $conn) {
            $conn->send($json);
        }

        $this->logger->debug('Broadcasted message to all WebSocket clients');
    }

    /**
     * Reads a message from the transport.
     *
     * Note: WebSocket transport does not support direct message reading as it's handled via onMessage.
     *
     * @return JsonRpcMessage|null Always returns null.
     */
    public function readMessage(): ?JsonRpcMessage {
        // WebSocket messages are handled via onMessage callback
        return null;
    }

    /**
     * Sends an error message to a specific WebSocket client.
     *
     * @param ConnectionInterface $conn    The connection interface.
     * @param int                 $code    The JSON-RPC error code.
     * @param string              $message The error message.
     * @param RequestId|null      $id      The request ID, if available.
     *
     * @return void
     */
    private function sendError(ConnectionInterface $conn, int $code, string $message, ?RequestId $id = null): void {
        $errorPayload = [
            'jsonrpc' => '2.0',
            'id' => $id ? $id->toString() : null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'data' => null,
            ],
        ];

        $json = json_encode($errorPayload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            $conn->send($json);
            $this->logger->debug('Sent error message to WebSocket client');
        } else {
            $this->logger->error('Failed to encode error message as JSON: ' . json_last_error_msg());
        }
    }

    /**
     * Builds a JsonRpcMessage from decoded data.
     *
     * @param array       $data       The decoded JSON data.
     * @param bool        $hasMethod  Whether 'method' key exists.
     * @param bool        $hasId      Whether 'id' key exists.
     * @param bool        $hasResult  Whether 'result' key exists.
     * @param bool        $hasError   Whether 'error' key exists.
     * @param RequestId|null $id      The request ID, if available.
     *
     * @throws McpError If message structure is invalid.
     *
     * @return JsonRpcMessage The constructed JsonRpcMessage object.
     */
    private function buildMessage(array $data, bool $hasMethod, bool $hasId, bool $hasResult, bool $hasError, ?RequestId $id): JsonRpcMessage {
        if ($hasError) {
            // It's a JSONRPCError
            $errorData = $data['error'];
            if (!isset($errorData['code']) || !isset($errorData['message'])) {
                throw new McpError(new ErrorData(
                    code: -32600,
                    message: 'Invalid Request: error object must contain code and message'
                ));
            }

            $errorObj = new JsonRpcErrorObject(
                code: $errorData['code'],
                message: $errorData['message'],
                data: $errorData['data'] ?? null
            );

            $errorMsg = new JSONRPCError(
                jsonrpc: '2.0',
                id: $id ?? new RequestId(''), // 'id' must be present for error
                error: $errorObj
            );

            $errorMsg->validate();
            return new JsonRpcMessage($errorMsg);
        } elseif ($hasMethod && $hasId && !$hasResult) {
            // It's a JSONRPCRequest
            $method = $data['method'];
            $params = isset($data['params']) && is_array($data['params']) ? $this->parseRequestParams($data['params']) : null;

            $req = new JSONRPCRequest(
                jsonrpc: '2.0',
                id: $id,
                params: $params,
                method: $method
            );

            $req->validate();
            return new JsonRpcMessage($req);
        } elseif ($hasMethod && !$hasId && !$hasResult && !$hasError) {
            // It's a JSONRPCNotification
            $method = $data['method'];
            $params = isset($data['params']) && is_array($data['params']) ? $this->parseNotificationParams($data['params']) : null;

            $not = new JSONRPCNotification(
                jsonrpc: '2.0',
                params: $params,
                method: $method
            );

            $not->validate();
            return new JsonRpcMessage($not);
        } elseif ($hasId && $hasResult && !$hasMethod && !$hasError) {
            // It's a JSONRPCResponse
            $result = $this->buildResult($data['result']);

            $resp = new JSONRPCResponse(
                jsonrpc: '2.0',
                id: $id,
                result: $result
            );

            $resp->validate();
            return new JsonRpcMessage($resp);
        } else {
            // Invalid message structure
            throw new McpError(new ErrorData(
                code: -32600,
                message: 'Invalid Request: Could not determine message type'
            ));
        }
    }

    /**
     * Parses request parameters from an associative array.
     *
     * @param array $paramsArr The parameters array from the JSON-RPC request.
     *
     * @return RequestParams The constructed RequestParams object.
     */
    private function parseRequestParams(array $paramsArr): RequestParams {
        $meta = null;
        if (isset($paramsArr['_meta']) && is_array($paramsArr['_meta'])) {
            $meta = $this->metaFromArray($paramsArr['_meta']);
        }

        $params = new RequestParams($_meta: $meta);

        // Assign other parameters dynamically
        foreach ($paramsArr as $key => $value) {
            if ($key !== '_meta') {
                $params->$key = $value;
            }
        }

        return $params;
    }

    /**
     * Parses notification parameters from an associative array.
     *
     * @param array $paramsArr The parameters array from the JSON-RPC notification.
     *
     * @return NotificationParams The constructed NotificationParams object.
     */
    private function parseNotificationParams(array $paramsArr): NotificationParams {
        $meta = null;
        if (isset($paramsArr['_meta']) && is_array($paramsArr['_meta'])) {
            $meta = $this->metaFromArray($paramsArr['_meta']);
        }

        $params = new NotificationParams($_meta: $meta);

        // Assign other parameters dynamically
        foreach ($paramsArr as $key => $value) {
            if ($key !== '_meta') {
                $params->$key = $value;
            }
        }

        return $params;
    }

    /**
     * Builds a Result object from an associative array.
     *
     * @param array $resultData The result data array from the JSON-RPC response.
     *
     * @return Result The constructed Result object.
     */
    private function buildResult(array $resultData): Result {
        $meta = null;
        if (isset($resultData['_meta']) && is_array($resultData['_meta'])) {
            $meta = $this->metaFromArray($resultData['_meta']);
        }

        $result = new Result($_meta: $meta);

        // Assign other result fields dynamically
        foreach ($resultData as $key => $value) {
            if ($key !== '_meta') {
                $result->$key = $value;
            }
        }

        return $result;
    }

    /**
     * Converts a meta array into a Meta object.
     *
     * @param array $metaArr The meta information array.
     *
     * @return \Mcp\Types\Meta The constructed Meta object.
     */
    private function metaFromArray(array $metaArr): \Mcp\Types\Meta {
        $meta = new \Mcp\Types\Meta();
        foreach ($metaArr as $key => $value) {
            $meta->$key = $value;
        }
        return $meta;
    }
}