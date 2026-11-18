<?php

declare(strict_types=1);

namespace Shirakaba\PubSub\Internal;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class Channels
{
    /**
     * @param non-empty-list<string>|string $channels
     * @return non-empty-list<string>
     * @throws InvalidArgumentException
     */
    public static function check(string|array $channels): array
    {
        $channels = is_string($channels) ? [$channels] : $channels;
        foreach ($channels as $channel) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $channel) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid channel name "%s": use up to 63 letters, digits and underscores, starting with a letter or underscore.', $channel));
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * Checks Mercure channel names, which become topics: path segments of unreserved
     * characters and percent-encodings, separated by "/", up to 1024 characters (design/11-real-time-events.md).
     *
     * @param non-empty-list<string>|string $channels
     * @return non-empty-list<string>
     * @throws InvalidArgumentException
     */
    public static function checkTopics(string|array $channels): array
    {
        $channels = is_string($channels) ? [$channels] : $channels;
        foreach ($channels as $channel) {
            if (strlen($channel) > 1024 || preg_match('#\A([A-Za-z0-9_.~-]|%[0-9A-Fa-f]{2})+(/([A-Za-z0-9_.~-]|%[0-9A-Fa-f]{2})+)*\z#', $channel) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid Mercure channel "%s": use path segments of letters, digits, "_", ".", "~", "-" and percent-encodings, separated by "/".', $channel));
            }
        }

        return array_values(array_unique($channels));
    }
}
