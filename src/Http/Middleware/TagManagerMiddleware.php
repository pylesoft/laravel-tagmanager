<?php

namespace Label84\TagManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Store as Session;
use Illuminate\Support\Collection;
use Label84\TagManager\TagManager;

class TagManagerMiddleware
{
    private TagManager $tagManager;

    private Session $session;

    public function __construct(TagManager $tagManager, Session $session)
    {
        $this->tagManager = $tagManager;
        $this->session = $session;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $sessionName = config('tagmanager.session_name');

        if ($this->session->has($sessionName)) {
            $this->tagManager->set(
                collect($this->normalizeSessionData($this->session->pull($sessionName)))
            );
        }

        $response = $next($request);

        $data = $this->tagManager->get()->all();

        if ($data !== []) {
            $this->session->flash($sessionName, $data);
        }

        return $response;
    }

    private function normalizeSessionData(mixed $value): array
    {
        $data = Collection::wrap($value)->all();
        $itemsKey = "\0*\0items";
        $escapeKey = "\0*\0escapeWhenCastingToString";

        // Older middleware cast Collections to arrays, persisting their protected properties.
        if (array_key_exists($itemsKey, $data)
            && array_key_exists($escapeKey, $data)
            && is_bool($data[$escapeKey])
            && (is_array($data[$itemsKey]) || $data[$itemsKey] instanceof Collection)) {
            $items = $this->normalizeSessionData($data[$itemsKey]);
            unset($data[$itemsKey], $data[$escapeKey]);

            // Keep events appended alongside the malformed wrapper, in their original order.
            return array_merge($items, $data);
        }

        return $data;
    }
}
