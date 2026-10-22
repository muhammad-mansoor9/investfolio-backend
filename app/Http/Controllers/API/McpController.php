<?php

namespace App\Http\Controllers\API;

use App\Exceptions\McpToolException;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\McpToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class McpController extends BaseController
{
    private const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private McpToolService $tools, private EntitlementService $entitlements)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        if (!$request->isMethod('POST')) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32000, 'message' => 'Only POST is supported; this server is stateless and does not provide an SSE stream'],
                'id' => null,
            ], 405);
        }

        $message = $request->json()->all();
        if (array_is_list($message) && $message !== []) {
            return $this->rpcError(null, -32600, 'Batch requests are not supported');
        }
        if (!isset($message['method']) || !is_string($message['method'])) {
            return $this->rpcError(null, -32700, 'Invalid JSON-RPC message');
        }

        $id = $message['id'] ?? null;
        $method = $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($id === null) {
            return response()->json(null, 202);
        }

        try {
            return match ($method) {
                'initialize' => $this->rpcResult($id, $this->initialize($params)),
                'ping' => $this->rpcResult($id, (object) []),
                'tools/list' => $this->rpcResult($id, ['tools' => $this->tools->definitions()]),
                'tools/call' => $this->toolCall($id, $params, $request->user()),
                default => $this->rpcError($id, -32601, "Method not found: {$method}"),
            };
        } catch (\Throwable $e) {
            Log::error('MCP internal error', ['method' => $method, 'error' => $e->getMessage()]);

            return $this->rpcError($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? self::PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'psx-analysis', 'version' => '1.0.0'],
            'instructions' => 'Market data for the Pakistan Stock Exchange (PSX), plus the signed-in user\'s own trading journal. '
                . 'Market data tools are read-only. Journal tools read, create, update and delete the user\'s entries; only write when the user asks, and confirm before deleting. '
                . 'Journal entries are tagged with PSX symbols and free-form labels; use list_journal_tags to see which exist. '
                . 'Stock symbols are uppercase tickers (e.g. OGDC, LUCK); resolve unknown companies with list_stocks search. '
                . 'Prices and financial statements are in PKR. '
                . 'FIPI/LIPI sector flows (get_fipi_lipi_sector_flows) are in USD while market flows (get_fipi_lipi_market_flows) are in PKR — never compare the two directly. '
                . 'Revenue/EPS growth is quarterly (QoQ, YoY, TTM); monthly earnings growth does not exist. '
                . 'Signal states are 1=Bearish to 5=Very Bullish.',
        ];
    }

    private function toolCall($id, array $params, User $user): JsonResponse
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];
        if (!is_string($name) || !$this->tools->has($name)) {
            return $this->rpcError($id, -32602, 'Unknown tool: ' . (is_string($name) ? $name : gettype($name)));
        }

        // The plan check lives here rather than in the auth middleware: an HTTP 403 makes some clients
        // restart OAuth, while a tool error keeps the connection and tells the user what to do.
        if (!$this->entitlements->includes($user, 'mcp_access')) {
            return $this->toolResult($id, [
                'error' => 'Investfolio MCP access requires the Pro plan. Upgrade at '
                    . rtrim((string) config('app.frontend_url'), '/') . '/billing',
            ], true);
        }

        try {
            $data = $this->tools->call($name, is_array($args) ? $args : []);

            return $this->toolResult($id, $data, false);
        } catch (ValidationException $e) {
            return $this->toolResult($id, ['error' => 'Invalid arguments', 'details' => $e->errors()], true);
        } catch (McpToolException $e) {
            return $this->toolResult($id, ['error' => $e->getMessage()], true);
        } catch (\Throwable $e) {
            Log::error('MCP tool error', ['tool' => $name, 'error' => $e->getMessage()]);

            return $this->toolResult($id, ['error' => 'Tool execution failed: ' . $e->getMessage()], true);
        }
    }

    private function toolResult($id, $data, bool $isError): JsonResponse
    {
        return $this->rpcResult($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
            'isError' => $isError,
        ]);
    }

    private function rpcResult($id, $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function rpcError($id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
