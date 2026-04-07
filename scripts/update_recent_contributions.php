<?php

declare(strict_types=1);

$readmePath = dirname(__DIR__) . '/README.md';
$startMarker = '<!-- recent-contributions:start -->';
$endMarker = '<!-- recent-contributions:end -->';
$username = getenv('GH_USERNAME') ?: 'prateekbhujel';
$maxItems = (int) (getenv('MAX_RECENT_PRS') ?: '5');

try {
    $readme = file_get_contents($readmePath);

    if ($readme === false) {
        throw new RuntimeException('Could not read README.md.');
    }

    $recentPullRequests = fetchRecentMergedPullRequests($username, $maxItems);
    $updatedReadme = replaceGeneratedBlock(
        $readme,
        renderPullRequestBlock($recentPullRequests),
        $startMarker,
        $endMarker,
    );

    if ($updatedReadme === $readme) {
        fwrite(STDOUT, "README is already up to date.\n");
        exit(0);
    }

    file_put_contents($readmePath, $updatedReadme);
    fwrite(STDOUT, "Updated README recent contributions section.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Failed to update README: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function fetchRecentMergedPullRequests(string $username, int $maxItems): array
{
    $query = http_build_query([
        'q' => sprintf('is:pr author:%s is:public is:merged', $username),
        'sort' => 'updated',
        'order' => 'desc',
        'per_page' => '20',
    ]);

    $payload = githubGetJson('https://api.github.com/search/issues?' . $query);
    $items = [];

    foreach ($payload['items'] ?? [] as $item) {
        $mergedAt = $item['pull_request']['merged_at'] ?? null;

        if (! is_string($mergedAt) || $mergedAt === '') {
            continue;
        }

        $repo = extractRepositoryName($item['repository_url'] ?? '');
        $owner = strtok($repo, '/');

        // Keep this focused on upstream contributions rather than PRs
        // opened against personal repositories.
        if ($owner !== false && strcasecmp($owner, $username) === 0) {
            continue;
        }

        $items[] = [
            'date' => substr($mergedAt, 0, 10),
            'title' => trim((string) ($item['title'] ?? 'Untitled pull request')),
            'url' => (string) ($item['html_url'] ?? ''),
            'repo' => $repo,
            'number' => (int) ($item['number'] ?? 0),
            'merged_at' => $mergedAt,
        ];
    }

    usort(
        $items,
        static fn (array $left, array $right): int => strcmp($right['merged_at'], $left['merged_at'])
    );

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
        return '- Watching for the next merged upstream pull request.';
    }

    $lines = [];

    foreach ($items as $item) {
        $lines[] = sprintf(
            '- `%s` [`%s#%d`](%s) - %s',
            $item['date'],
            $item['repo'],
            $item['number'],
            $item['url'],
            $item['title'],
        );
    }

    return implode(PHP_EOL, $lines);
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
