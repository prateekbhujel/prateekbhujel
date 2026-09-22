<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/update_recent_contributions.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$entry = ['repo' => 'php/php-src', 'number' => 23838, 'sha' => str_repeat('a', 40)];
$pullRequest = [
    'base' => ['repo' => ['full_name' => 'php/php-src', 'private' => false]],
    'number' => 23838,
    'state' => 'closed',
    'user' => ['login' => 'contributor'],
    'title' => 'Fix a test',
    'html_url' => 'https://github.com/php/php-src/pull/23838',
];
$commit = ['sha' => $entry['sha'], 'author' => ['login' => 'contributor']];
$comparison = ['status' => 'ahead', 'merge_base_commit' => ['sha' => $entry['sha']]];
$events = [[
    'event' => 'closed',
    'commit_id' => $entry['sha'],
    'commit_url' => 'https://api.github.com/repos/php/php-src/commits/' . $entry['sha'],
    'created_at' => '2026-09-22T08:40:52Z',
]];

$normalize = static fn (array $pr, array $c, array $compare, array $e): ?array =>
    normalizeDirectlyAppliedContribution($entry, $pr, $c, $compare, $e, 'contributor');
$applied = $normalize($pullRequest, $commit, $comparison, $events);
check($applied !== null && $applied['kind'] === 'applied', 'Accept a verified directly applied patch.');
check($applied['date'] === '2026-09-22', 'Use the upstream acceptance date.');

$invalidCases = [
    'Closed without a linked commit' => [$pullRequest, $commit, $comparison, []],
    'Commit was only mentioned' => [$pullRequest, $commit, $comparison, array_replace_recursive($events, [0 => ['event' => 'referenced']])],
    'Another commit closed the PR' => [$pullRequest, $commit, $comparison, array_replace_recursive($events, [0 => ['commit_id' => str_repeat('b', 40)]])],
    'A commit from a fork closed the PR' => [$pullRequest, $commit, $comparison, array_replace_recursive($events, [0 => ['commit_url' => 'https://api.github.com/repos/contributor/php-src/commits/' . $entry['sha']]])],
    'Another contributor authored the commit' => [$pullRequest, array_replace_recursive($commit, ['author' => ['login' => 'someone-else']]), $comparison, $events],
    'Commit has no verified GitHub author' => [$pullRequest, array_replace($commit, ['author' => null]), $comparison, $events],
    'Another contributor opened the PR' => [array_replace_recursive($pullRequest, ['user' => ['login' => 'someone-else']]), $commit, $comparison, $events],
    'PR is still open' => [array_replace($pullRequest, ['state' => 'open']), $commit, $comparison, $events],
    'Wrong PR returned' => [array_replace($pullRequest, ['number' => 23839]), $commit, $comparison, $events],
    'Repository is private' => [array_replace_recursive($pullRequest, ['base' => ['repo' => ['private' => true]]]), $commit, $comparison, $events],
    'Wrong upstream repository' => [array_replace_recursive($pullRequest, ['base' => ['repo' => ['full_name' => 'other/php-src']]]), $commit, $comparison, $events],
    'Wrong commit returned' => [$pullRequest, array_replace($commit, ['sha' => str_repeat('b', 40)]), $comparison, $events],
    'Commit is not on upstream default branch' => [$pullRequest, $commit, array_replace($comparison, ['status' => 'diverged']), $events],
    'Comparison has a different merge base' => [$pullRequest, $commit, array_replace_recursive($comparison, ['merge_base_commit' => ['sha' => str_repeat('b', 40)]]), $events],
];

foreach ($invalidCases as $message => $arguments) {
    check($normalize(...$arguments) === null, $message);
}

check(normalizeDirectlyAppliedContribution(
    array_replace($entry, ['repo' => 'contributor/project']),
    array_replace_recursive($pullRequest, ['base' => ['repo' => ['full_name' => 'contributor/project']]]),
    $commit,
    $comparison,
    $events,
    'contributor',
) === null, 'Exclude directly applied patches in personal repositories.');

$merged = normalizeMergedPullRequestItem([
    'repository_url' => 'https://api.github.com/repos/php/php-src',
    'number' => 21835,
    'title' => 'Earlier fix',
    'html_url' => 'https://github.com/php/php-src/pull/21835',
    'pull_request' => ['merged_at' => '2026-05-04T12:00:00Z'],
], 'contributor');
check($merged !== null && $merged['kind'] === 'merged', 'Keep ordinary merged PRs.');
check(normalizeMergedPullRequestItem([
    'repository_url' => 'https://api.github.com/repos/php/php-src',
    'pull_request' => ['merged_at' => null],
], 'contributor') === null, 'Do not count closed-only search results.');
check(normalizeMergedPullRequestItem([
    'repository_url' => 'https://api.github.com/repos/contributor/project',
    'pull_request' => ['merged_at' => '2026-09-22T12:00:00Z'],
], 'contributor') === null, 'Exclude personal repositories.');

$items = selectRecentContributions([$merged], [$applied, $applied], 20);
check(count($items) === 2 && $items[0]['number'] === 23838, 'Deduplicate and sort all contributions together.');
check(count(selectRecentContributions([$merged], [$applied], 1)) === 1, 'Apply the shared item limit.');
$laterMerged = array_replace($applied, ['kind' => 'merged']);
check(selectRecentContributions([$laterMerged], [$applied], 20)[0]['kind'] === 'merged', 'Prefer the merged PR if it later merges.');

$rendered = renderPullRequestBlock($items);
check(str_contains($rendered, '[Applied commit](' . $applied['commit_url'] . ')'), 'Link direct application evidence.');
check(str_contains($rendered, 'merged May 4, 2026'), 'Retain merged status for ordinary PRs.');
check(! str_contains($rendered, 'merged Sep 22, 2026'), 'Do not call directly applied PRs merged.');
check(str_contains($rendered, 'accepted patches: 2'), 'Count accepted patches accurately.');

fwrite(STDOUT, "Contribution filtering tests passed.\n");
