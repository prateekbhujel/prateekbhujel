<?php

declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(updateReadme());
}

function updateReadme(): int
{
    $readmePath = dirname(__DIR__) . '/README.md';
    $startMarker = '<!-- open-source-prs:start -->';
    $endMarker = '<!-- open-source-prs:end -->';
    $username = getenv('GH_USERNAME') ?: 'prateekbhujel';
    $maxItems = readPositiveIntegerEnv('MAX_MERGED_PRS', 20);

    try {
        $readme = file_get_contents($readmePath);

        if ($readme === false) {
            throw new RuntimeException('Could not read README.md.');
        }

        $pullRequests = selectRecentContributions(
            fetchMergedUpstreamPullRequests($username, $maxItems),
            fetchDirectlyAppliedContributions($username),
            $maxItems,
        );
        $updatedReadme = replaceGeneratedBlock(
            $readme,
            renderPullRequestBlock($pullRequests),
            $startMarker,
            $endMarker,
        );

        if ($updatedReadme === $readme) {
            fwrite(STDOUT, "README is already up to date.\n");
            return 0;
        }

        file_put_contents($readmePath, $updatedReadme);
        fwrite(STDOUT, "Updated README accepted contributions section.\n");
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Failed to update README: ' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

function readPositiveIntegerEnv(string $name, int $default): int
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        return $default;
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return is_int($integer) ? $integer : $default;
}

function fetchMergedUpstreamPullRequests(string $username, int $maxItems): array
{
    $items = [];
    $page = 1;
    $perPage = 100;
    $maxSearchPages = 10; // GitHub Search exposes at most 1,000 results.

    do {
        $query = http_build_query([
            'q' => sprintf('is:pr author:%s is:public is:merged', $username),
            'sort' => 'updated',
            'order' => 'desc',
            'per_page' => (string) $perPage,
            'page' => (string) $page,
        ]);

        $payload = githubGetJson('https://api.github.com/search/issues?' . $query);
        $pageItems = $payload['items'] ?? [];

        if (! is_array($pageItems) || $pageItems === []) {
            break;
        }

        foreach ($pageItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $pullRequest = normalizeMergedPullRequestItem($item, $username);

            if ($pullRequest === null) {
                continue;
            }

            $items[] = $pullRequest;

        }

        $page++;
    } while (count($pageItems) === $perPage && $page <= $maxSearchPages);

    usort($items, static fn (array $left, array $right): int => strcmp($right['sort_at'], $left['sort_at']));

    return array_slice($items, 0, $maxItems);
}

function normalizeMergedPullRequestItem(array $item, string $username): ?array
{
    $repo = extractRepositoryName((string) ($item['repository_url'] ?? ''));
    $repoParts = explode('/', $repo, 2);
    $owner = $repoParts[0] ?? '';

    // Keep this focused on upstream contributions rather than PRs
    // opened against personal repositories.
    if ($repo === '' || strcasecmp($owner, $username) === 0) {
        return null;
    }

    $pullRequest = $item['pull_request'] ?? [];
    $mergedAt = is_array($pullRequest) ? ($pullRequest['merged_at'] ?? null) : null;

    if (! is_string($mergedAt) || $mergedAt === '') {
        return null;
    }

    return [
        'date' => substr($mergedAt, 0, 10),
        'title' => trim((string) ($item['title'] ?? 'Untitled pull request')),
        'url' => (string) ($item['html_url'] ?? ''),
        'repo' => $repo,
        'number' => (int) ($item['number'] ?? 0),
        'sort_at' => $mergedAt,
        'kind' => 'merged',
    ];
}

function fetchDirectlyAppliedContributions(string $username): array
{
    $items = [];

    foreach (require __DIR__ . '/directly_applied.php' as $entry) {
        $api = 'https://api.github.com/repos/' . $entry['repo'];
        $pullRequest = githubGetJson($api . '/pulls/' . $entry['number']);
        $commit = githubGetJson($api . '/commits/' . $entry['sha']);
        $branch = $pullRequest['base']['repo']['default_branch'] ?? '';
        $comparison = githubGetJson($api . '/compare/' . $entry['sha'] . '...' . rawurlencode($branch));
        $events = [];
        $page = 1;

        do {
            $pageEvents = githubGetJson($api . '/issues/' . $entry['number'] . '/events?per_page=100&page=' . $page++);
            $events = array_merge($events, $pageEvents);
        } while (count($pageEvents) === 100);

        $item = normalizeDirectlyAppliedContribution($entry, $pullRequest, $commit, $comparison, $events, $username);

        if ($item === null) {
            throw new RuntimeException('Could not verify applied contribution ' . $entry['repo'] . '#' . $entry['number'] . '.');
        }

        $items[] = $item;
    }

    return $items;
}

function normalizeDirectlyAppliedContribution(
    array $entry,
    array $pullRequest,
    array $commit,
    array $comparison,
    array $events,
    string $username,
): ?array {
    $repo = $entry['repo'];
    $sha = $entry['sha'];
    $upstream = $pullRequest['base']['repo'] ?? [];

    if (($upstream['full_name'] ?? '') !== $repo || ($upstream['private'] ?? true)
        || strcasecmp(explode('/', $repo)[0], $username) === 0
        || ($pullRequest['number'] ?? 0) !== $entry['number']
        || ($pullRequest['state'] ?? '') !== 'closed'
        || strcasecmp($pullRequest['user']['login'] ?? '', $username) !== 0
        || strcasecmp($commit['author']['login'] ?? '', $username) !== 0
        || ($commit['sha'] ?? '') !== $sha
        || ! in_array($comparison['status'] ?? '', ['ahead', 'identical'], true)
        || ($comparison['merge_base_commit']['sha'] ?? '') !== $sha) {
        return null;
    }

    foreach ($events as $event) {
        if (($event['event'] ?? '') !== 'closed' || ($event['commit_id'] ?? '') !== $sha
            || ($event['commit_url'] ?? '') !== 'https://api.github.com/repos/' . $repo . '/commits/' . $sha
            || empty($event['created_at'])) {
            continue;
        }

        return [
            'date' => substr($event['created_at'], 0, 10),
            'title' => trim($pullRequest['title']),
            'url' => $pullRequest['html_url'],
            'repo' => $repo,
            'number' => $entry['number'],
            'sort_at' => $event['created_at'],
            'kind' => 'applied',
            'commit_url' => 'https://github.com/' . $repo . '/commit/' . $sha,
        ];
    }

    return null;
}

function selectRecentContributions(array $merged, array $applied, int $maxItems): array
{
    $items = [];

    foreach (array_merge($merged, $applied) as $item) {
        $key = strtolower($item['repo']) . '#' . $item['number'];
        $items[$key] ??= $item;
    }

    usort($items, static fn (array $left, array $right): int => strcmp($right['sort_at'], $left['sort_at']));

    return array_slice($items, 0, $maxItems);
}

function githubGetJson(string $url): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: prateekbhujel-profile-readme-updater',
    ];

    $token = getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');

    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        throw new RuntimeException('GitHub API request failed.');
    }

    $statusCode = extractStatusCode($http_response_header ?? []);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('GitHub API returned HTTP ' . $statusCode . '.');
    }

    $decoded = json_decode($response, true);

    if (! is_array($decoded)) {
        throw new RuntimeException('GitHub API returned invalid JSON.');
    }

    return $decoded;
}

function extractStatusCode(array $headers): int
{
    $statusLine = $headers[0] ?? '';

    if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function extractRepositoryName(string $repositoryUrl): string
{
    $path = (string) parse_url($repositoryUrl, PHP_URL_PATH);

    return ltrim(preg_replace('#^/repos/#', '', $path) ?? '', '/');
}

function renderPullRequestBlock(array $items): string
{
    if ($items === []) {
        return '- No accepted public upstream patches found yet.';
    }

    $lines = [];

    $lines[] = renderBadges($items);
    $lines[] = '';
    $lines[] = renderSpotlightTable($items);
    $lines[] = '';
    $lines[] = '<strong>Recent accepted patches</strong>';
    $lines[] = '';
    $lines[] = renderCardGrid($items);
    $lines[] = '';
    $lines[] = renderLedger($items);

    return implode(PHP_EOL, $lines);
}

function renderBadges(array $items): string
{
    $repoCount = count(array_unique(array_column($items, 'repo')));
    $latestMerge = formatDisplayDate($items[0]['date']);

    return implode(PHP_EOL, [
        '<p>',
        renderBadge('accepted patches', (string) count($items), '238636'),
        renderBadge('upstream repos', (string) $repoCount, '0969da'),
        renderBadge('latest accepted', $latestMerge, 'f97316'),
        '</p>',
    ]);
}

function renderBadge(string $label, string $message, string $color): string
{
    $url = sprintf(
        'https://img.shields.io/badge/%s-%s-%s?style=for-the-badge&labelColor=0d1117',
        rawurlencode($label),
        rawurlencode($message),
        $color,
    );

    return sprintf('<img alt="%s: %s" src="%s">', escapeHtml($label), escapeHtml($message), $url);
}

function renderSpotlightTable(array $items): string
{
    $latest = $items[0];
    $repoCounts = array_count_values(array_column($items, 'repo'));
    arsort($repoCounts);
    $topRepo = (string) array_key_first($repoCounts);
    $topRepoCount = (int) ($repoCounts[$topRepo] ?? 0);
    $oldest = $items[array_key_last($items)];

    return implode(PHP_EOL, [
        '<table>',
        '<tr>',
        sprintf(
            '<td width="33%%" valign="top"><strong>Newest accepted patch</strong><br><a href="%s">#%d %s</a><br><sub><code>%s</code> - %s</sub></td>',
            escapeHtml($latest['url']),
            $latest['number'],
            escapeHtml($latest['title']),
            escapeHtml($latest['repo']),
            formatDisplayDate($latest['date']),
        ),
        sprintf(
            '<td width="33%%" valign="top"><strong>Most represented upstream</strong><br><code>%s</code><br><sub>%d accepted %s in this view</sub></td>',
            escapeHtml($topRepo),
            $topRepoCount,
            $topRepoCount === 1 ? 'patch' : 'patches',
        ),
        sprintf(
            '<td width="33%%" valign="top"><strong>Acceptance window</strong><br>%s to %s<br><sub>Newest first, upstream-only</sub></td>',
            formatDisplayDate($oldest['date']),
            formatDisplayDate($latest['date']),
        ),
        '</tr>',
        '</table>',
    ]);
}

function renderCardGrid(array $items): string
{
    $lines = ['<table>'];

    foreach (array_chunk($items, 2) as $rowIndex => $rowItems) {
        $lines[] = '<tr>';

        foreach ($rowItems as $itemIndex => $item) {
            $position = ($rowIndex * 2) + $itemIndex + 1;
            $lines[] = renderPullRequestCard($item, $position);
        }

        if (count($rowItems) === 1) {
            $lines[] = '<td width="50%" valign="top"></td>';
        }

        $lines[] = '</tr>';
    }

    $lines[] = '</table>';

    return implode(PHP_EOL, $lines);
}

function renderPullRequestCard(array $item, int $position): string
{
    return sprintf(
        '<td width="50%%" valign="top"><strong>%02d. <a href="%s">#%d %s</a></strong><br><sub><code>%s</code> - %s %s</sub></td>',
        $position,
        escapeHtml($item['url']),
        $item['number'],
        escapeHtml($item['title']),
        escapeHtml($item['repo']),
        $item['kind'] === 'applied' ? '<a href="' . escapeHtml($item['commit_url']) . '">applied</a>' : 'merged',
        formatDisplayDate($item['date']),
    );
}

function renderLedger(array $items): string
{
    $lines = [
        '<details>',
        '<summary><strong>Compact contribution ledger</strong> - newest first</summary>',
        '',
        '| Accepted | Upstream | Pull request | Landed as |',
        '| --- | --- | --- | --- |',
    ];

    foreach ($items as $item) {
        $lines[] = sprintf(
            '| %s | `%s` | [#%d %s](%s) | %s |',
            formatDisplayDate($item['date']),
            escapeTableCell($item['repo']),
            $item['number'],
            escapeTableCell($item['title']),
            $item['url'],
            $item['kind'] === 'applied' ? '[Applied commit](' . $item['commit_url'] . ')' : 'Merged PR',
        );
    }

    $lines[] = '';
    $lines[] = '</details>';

    return implode(PHP_EOL, $lines);
}

function formatDisplayDate(string $date): string
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date('M j, Y', $timestamp);
}

function escapeTableCell(string $value): string
{
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return str_replace(['|', '[', ']'], ['\\|', '\\[', '\\]'], trim($value));
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function replaceGeneratedBlock(
    string $readme,
    string $replacement,
    string $startMarker,
    string $endMarker,
): string {
    $pattern = sprintf(
        '/(%s\n)(.*?)(\n%s)/s',
        preg_quote($startMarker, '/'),
        preg_quote($endMarker, '/'),
    );

    $count = 0;
    $updated = preg_replace_callback(
        $pattern,
        static fn (array $matches): string => $matches[1] . $replacement . $matches[3],
        $readme,
        1,
        $count,
    );

    if (! is_string($updated) || $count !== 1) {
        throw new RuntimeException('README markers not found.');
    }

    return $updated;
}
