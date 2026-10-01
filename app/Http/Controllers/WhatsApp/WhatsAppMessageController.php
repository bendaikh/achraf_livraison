<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\MessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WhatsAppMessageController extends Controller
{
    protected function companyId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->resolveCompanyId();
    }

    public function store(Request $request, WhatsAppConversation $conversation, MessageSender $sender): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $message = $sender->sendText($conversation, trim($data['body']), $request->user());

            return response()->json([
                'message' => $message->toApiArray(),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function storeMedia(Request $request, WhatsAppConversation $conversation, MessageSender $sender): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:16384'],
            'type' => ['required', 'in:image,video,audio,document'],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        try {
            $message = $sender->sendUploadedMedia(
                $conversation,
                $file->getRealPath(),
                $mime,
                $data['type'],
                $data['caption'] ?? null,
                $file->getClientOriginalName(),
                $request->user()
            );

            return response()->json([
                'message' => $message->toApiArray(),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function storeTemplate(Request $request, WhatsAppConversation $conversation, MessageSender $sender): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:whatsapp_templates,id'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:255'],
        ]);

        $template = \App\Models\WhatsAppTemplate::query()
            ->where('company_id', $conversation->company_id)
            ->where('id', $data['template_id'])
            ->firstOrFail();

        try {
            $message = $sender->sendTemplate(
                $conversation,
                $template,
                $data['variables'] ?? [],
                $request->user()
            );

            return response()->json(['message' => $message->toApiArray()], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function media(Request $request, WhatsAppMessage $message): StreamedResponse|JsonResponse
    {
        if ($message->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        if (! $message->media_path || ! Storage::disk('local')->exists($message->media_path)) {
            return response()->json(['message' => 'Média introuvable.'], 404);
        }

        return Storage::disk('local')->response(
            $message->media_path,
            $message->media_filename ?: basename($message->media_path),
            ['Content-Type' => $message->media_mime ?: 'application/octet-stream']
        );
    }
}
