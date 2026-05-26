<?php

declare(strict_types=1);

$readmePath = dirname(__DIR__) . '/README.md';
$startMarker = '<!-- open-source-prs:start -->';
$endMarker = '<!-- open-source-prs:end -->';
$username = getenv('GH_USERNAME') ?: 'prateekbhujel';
$maxItems = readPositiveIntegerEnv('MAX_OPEN_SOURCE_PRS', 20);
$pageSize = readPositiveIntegerEnv('PR_PAGE_SIZE', 10);

try {
    $readme = file_get_contents($readmePath);

    if ($readme === false) {
        throw new RuntimeException('Could not read README.md.');
    }

    $pullRequests = fetchUpstreamPullRequests($username, $maxItems);
    $updatedReadme = replaceGeneratedBlock(
        $readme,
        renderPullRequestBlock($pullRequests, $pageSize),
        $startMarker,
        $endMarker,
    );

    if ($updatedReadme === $readme) {
        fwrite(STDOUT, "README is already up to date.\n");
        exit(0);
    }

    file_put_contents($readmePath, $updatedReadme);
    fwrite(STDOUT, "Updated README open source pull request section.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Failed to update README: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
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

function fetchUpstreamPullRequests(string $username, int $maxItems): array
{
    $items = [];
    $page = 1;
    $perPage = 100;
    $maxSearchPages = 10; // GitHub Search exposes at most 1,000 results.

    do {
        $query = http_build_query([
            'q' => sprintf('is:pr author:%s is:public', $username),
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

            $pullRequest = normalizePullRequestItem($item, $username);

            if ($pullRequest === null) {
                continue;
            }

            $items[] = $pullRequest;

            if (count($items) >= $maxItems) {
                break 2;
            }
        }

        $page++;
    } while (count($pageItems) === $perPage && $page <= $maxSearchPages);

    usort($items, static fn (array $left, array $right): int => strcmp($right['sort_at'], $left['sort_at']));

    return array_slice($items, 0, $maxItems);
}

function normalizePullRequestItem(array $item, string $username): ?array
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
    $closedAt = $item['closed_at'] ?? null;
    $updatedAt = $item['updated_at'] ?? null;
    $createdAt = $item['created_at'] ?? null;
    $state = (string) ($item['state'] ?? '');

    if (is_string($mergedAt) && $mergedAt !== '') {
        $status = 'Merged';
        $sortAt = $mergedAt;
    } elseif ($state === 'closed') {
        $status = 'Closed';
        $sortAt = is_string($closedAt) && $closedAt !== '' ? $closedAt : (string) $updatedAt;
    } else {
        $status = 'Open';
        $sortAt = is_string($updatedAt) && $updatedAt !== '' ? $updatedAt : (string) $createdAt;
    }

    if ($sortAt === '') {
        $sortAt = date(DATE_ATOM, 0);
    }

    return [
        'date' => substr($sortAt, 0, 10),
        'title' => trim((string) ($item['title'] ?? 'Untitled pull request')),
        'url' => (string) ($item['html_url'] ?? ''),
        'repo' => $repo,
        'number' => (int) ($item['number'] ?? 0),
        'status' => $status,
        'sort_at' => $sortAt,
    ];
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

function renderPullRequestBlock(array $items, int $pageSize): string
{
    if ($items === []) {
        return '- No public upstream pull requests found yet.';
    }

    $lines = [];
    $pageCount = (int) ceil(count($items) / $pageSize);

    $lines[] = renderSummary($items);

    if ($pageCount > 1) {
        $lines[] = '';
        $lines[] = renderPageLinks($pageCount);
    }

    foreach (array_chunk($items, $pageSize) as $index => $pageItems) {
        $pageNumber = $index + 1;
        $start = $index * $pageSize + 1;
        $end = $start + count($pageItems) - 1;

        $lines[] = '';
        $lines[] = sprintf('<a id="open-source-prs-page-%d"></a>', $pageNumber);
        $lines[] = sprintf('<details%s>', $pageNumber === 1 ? ' open' : '');
        $lines[] = sprintf('<summary><strong>Page %d</strong> - PRs %d-%d</summary>', $pageNumber, $start, $end);
        $lines[] = '';
        $lines[] = '| Date | Pull request | Repo | Status |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($pageItems as $item) {
            $lines[] = sprintf(
                '| %s | [#%d %s](%s) | `%s` | %s |',
                formatDisplayDate($item['date']),
                $item['number'],
                escapeTableCell($item['title']),
                $item['url'],
                escapeTableCell($item['repo']),
                $item['status'],
            );
        }

        if ($pageCount > 1) {
            $lines[] = '';
            $lines[] = renderSiblingLinks($pageNumber, $pageCount);
        }

        $lines[] = '';
        $lines[] = '</details>';
    }

    return implode(PHP_EOL, $lines);
}

function renderSummary(array $items): string
{
    $counts = [
        'Merged' => 0,
        'Open' => 0,
        'Closed' => 0,
    ];

    foreach ($items as $item) {
        if (isset($counts[$item['status']])) {
            $counts[$item['status']]++;
        }
    }

    $parts = [];

    foreach ($counts as $status => $count) {
        if ($count > 0) {
            $parts[] = sprintf('%s: %d', $status, $count);
        }
    }

    return sprintf(
        '**%d upstream PR%s tracked** across public non-personal repositories. %s.',
        count($items),
        count($items) === 1 ? '' : 's',
        implode(', ', $parts),
    );
}

function renderPageLinks(int $pageCount): string
{
    $links = [];

    for ($page = 1; $page <= $pageCount; $page++) {
        $links[] = sprintf('[%d](#open-source-prs-page-%d)', $page, $page);
    }

    return 'Pages: ' . implode(' | ', $links);
}

function renderSiblingLinks(int $pageNumber, int $pageCount): string
{
    $links = [];

    if ($pageNumber > 1) {
        $links[] = sprintf('[Previous](#open-source-prs-page-%d)', $pageNumber - 1);
    }

    if ($pageNumber < $pageCount) {
        $links[] = sprintf('[Next](#open-source-prs-page-%d)', $pageNumber + 1);
    }

    return implode(' | ', $links);
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
