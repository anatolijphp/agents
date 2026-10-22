<?php

namespace Packstub\Agents\Ai\Middleware;

use Closure;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\PendingStep;
use Packstub\Agents\Ai\Agent;

/**
 * The dynamic block (date and time, workspace, person, language, page
 * context) rides with the question instead of the system prompt, so the
 * instructions, the tool list and the history in front of it are
 * byte-identical from one turn to the next and the provider's prompt cache
 * keeps hitting. It runs last: the app's middleware reads the question as
 * typed. The block is written once per turn, into the question message
 * itself, so the step's messages stay the very array laravel/ai sent: a
 * provider's stateful continuation (previous_response_id on OpenAI and
 * xAI) replays only unchanged messages, and a replaced message would have
 * cost every tool step the whole history again. laravel/ai builds that
 * message from the prompt string for the loop alone, so the stored question
 * stays as typed, and the model reads the same messages while it calls
 * tools. A turn that resumes an approval has no question to carry it and
 * goes without — the model continues the step the block already informed.
 */
class AttachContext
{
    /** @var array<string, string|false> The block of each turn seen (by invocation), or false when the turn carries no question. */
    protected array $blocks = [];

    public function __construct(protected ?Agent $agent = null) {}

    public function handle(PendingStep $step, Closure $next)
    {
        $key = (string) ($step->invocationId ?? '');

        if ($step->isFirstStep() || ! array_key_exists($key, $this->blocks)) {
            $this->blocks[$key] = $this->agent !== null && $step->isFirstStep() && EnforceBudget::question($step) !== null
                ? $this->agent->dynamicInstructions()
                : false;
        }

        $block = $this->blocks[$key];

        if ($block === false || trim($block) === '') {
            return $next($step);
        }

        $question = self::question($step->messages);

        // In place, and once: the tool steps of the turn carry the same message object, already informed.
        if ($question !== null && ! str_starts_with((string) $question->content, $block.PHP_EOL)) {
            $question->content = $block.PHP_EOL.PHP_EOL.$question->content;
        }

        return $next($step);
    }

    /** The last user message of the step, the question the turn answers. */
    protected static function question(array $messages): ?Message
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof Message && $messages[$i]->role === MessageRole::User) {
                return $messages[$i];
            }
        }

        return null;
    }
}
