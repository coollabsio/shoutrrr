<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Dto\Engagement\ReplyPostResult;
use App\Http\Requests\Engagement\RespondToReplyRequest;
use App\Mcp\Tools\Concerns\EngagementTool;
use App\Services\Engagement\ReplyInbox;
use App\Support\ReplyListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('respond_to_reply')]
#[Description('Publicly reply to an inbound reply from the account that published the post. Text-only replies post immediately; replies with media are queued (send_status "sending"). Requires confirm=true.')]
class RespondToReplyTool extends EngagementTool
{
    public function handle(Request $request, ReplyInbox $inbox): Response
    {
        if ($error = $this->bindEngagement($request)) {
            return $error;
        }

        $request->validate(['reply_id' => ['required', 'string']]);

        $reply = $this->findReply((string) $request->get('reply_id'));

        if ($reply === null) {
            return $this->replyNotFound();
        }

        $validated = $request->validate(RespondToReplyRequest::rulesFor($reply));

        if ($unconfirmed = $this->requireConfirmation($request, 'This will publish a public reply on '.$reply->platform->value.'.')) {
            return $unconfirmed;
        }

        $result = $inbox->respond($reply, (string) ($validated['text'] ?? ''), array_values($validated['media'] ?? []));

        if ($result instanceof ReplyPostResult) {
            return $this->actionFailed($result->message, 'Could not post the reply.');
        }

        return Response::text(json_encode(['reply' => ReplyListItem::make($result)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply_id' => $schema->string()->description('Id of the inbound reply to answer.')->required(),
            'text' => $schema->string()->description('Reply text. Required unless media is given; limited to the account\'s max text length.'),
            'media' => $schema->array()->items($schema->string())->description('Ids of media already uploaded to this workspace to attach. Only platforms that support reply media accept it.'),
            'confirm' => $schema->boolean()->description('Must be true to publish.'),
        ];
    }
}
