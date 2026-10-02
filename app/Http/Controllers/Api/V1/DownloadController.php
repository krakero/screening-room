<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TorrentResource;
use App\Services\Qbittorrent\QbittorrentClient;
use App\Services\Qbittorrent\QbittorrentException;
use Illuminate\Http\JsonResponse;

class DownloadController extends Controller
{
    public function index(QbittorrentClient $client): JsonResponse
    {
        if (! $client->configured()) {
            return response()->json([
                'data' => [],
                'meta' => ['configured' => false, 'web_ui_url' => null],
            ]);
        }

        try {
            $torrents = $client->torrents();
        } catch (QbittorrentException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'data' => TorrentResource::collection($torrents)->resolve(),
            'meta' => ['configured' => true, 'web_ui_url' => $client->webUiUrl()],
        ]);
    }
}
