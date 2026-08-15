<?php

declare(strict_types=1);

function light_directory_groups(string $type, string $table, string $column, int $ttlSeconds = 600): array
{
    $allowed = ['actress', 'genre', 'maker', 'label'];
    if (!in_array($type, $allowed, true)) {
        return [];
    }

    $cacheDirectory = dirname(__DIR__) . '/storage/cache/light-directory';
    $cacheFile = $cacheDirectory . '/v2-' . $type . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttlSeconds) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $groups = [
        'kana-a' => ['title' => 'あ行', 'names' => []],
        'kana-ka' => ['title' => 'か行', 'names' => []],
        'kana-sa' => ['title' => 'さ行', 'names' => []],
        'kana-ta' => ['title' => 'た行', 'names' => []],
        'kana-na' => ['title' => 'な行', 'names' => []],
        'kana-ha' => ['title' => 'は行', 'names' => []],
        'kana-ma' => ['title' => 'ま行', 'names' => []],
        'kana-ya' => ['title' => 'や行', 'names' => []],
        'kana-ra' => ['title' => 'ら行', 'names' => []],
        'kana-wa' => ['title' => 'わ行', 'names' => []],
        'alpha' => ['title' => 'A〜Z', 'letters' => []],
        'other' => ['title' => 'その他', 'names' => []],
    ];

    try {
        if (!db_table_exists($table)) {
            return $groups;
        }
        $itemWhere = [];
        if (db_column_exists('items', 'item_source')) {
            $itemWhere[] = 'i.item_source = "fanza_product"';
        }
        if (db_column_exists('items', 'service_code')) {
            $itemWhere[] = 'LOWER(COALESCE(i.service_code, "")) = "digital"';
        }
        if (db_column_exists('items', 'floor_code')) {
            $itemWhere[] = 'LOWER(COALESCE(i.floor_code, "")) = "videoc"';
        }
        if (db_column_exists('items', 'release_date')) {
            $itemWhere[] = '(i.release_date IS NULL OR i.release_date = "" OR i.release_date <= NOW())';
        }
        $itemWhereSql = $itemWhere !== [] ? implode(' AND ', $itemWhere) : '1=1';

        if (db_column_exists($table, 'item_id')) {
            $nameSelect = 'SELECT r.`' . $column . '` AS name FROM `' . $table . '` r'
                . ' INNER JOIN items i ON i.id = r.item_id'
                . ' WHERE r.`' . $column . '` IS NOT NULL AND r.`' . $column . '` <> "" AND ' . $itemWhereSql;
        } else {
            $nameSelect = 'SELECT `' . $column . '` AS name FROM `' . $table . '`'
                . ' WHERE `' . $column . '` IS NOT NULL AND `' . $column . '` <> ""';
        }

        if ($type === 'actress') {
            // Existing videoc rows may predate title-to-performer normalization.
            // Add only titles of items which have no actress relation.
            $nameSelect .= ' UNION ALL SELECT i.title AS name FROM items i'
                . ' WHERE ' . $itemWhereSql
                . ' AND TRIM(COALESCE(i.title, "")) <> ""'
                . ' AND CHAR_LENGTH(i.title) <= 80'
                . (db_column_exists($table, 'item_id')
                    ? ' AND NOT EXISTS (SELECT 1 FROM `' . $table . '` fallback_relation WHERE fallback_relation.item_id = i.id)'
                    : '');
        }

        $stmt = db()->query('SELECT name FROM (' . $nameSelect . ') directory_names GROUP BY name ORDER BY name ASC LIMIT 20000');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $first = mb_substr($name, 0, 1, 'UTF-8');
            $hiragana = mb_convert_kana($first, 'c', 'UTF-8');
            $key = match (true) {
                (bool)preg_match('/^[ぁ-お]/u', $hiragana) => 'kana-a',
                (bool)preg_match('/^[か-ご]/u', $hiragana) => 'kana-ka',
                (bool)preg_match('/^[さ-ぞ]/u', $hiragana) => 'kana-sa',
                (bool)preg_match('/^[た-ど]/u', $hiragana) => 'kana-ta',
                (bool)preg_match('/^[な-の]/u', $hiragana) => 'kana-na',
                (bool)preg_match('/^[は-ぽ]/u', $hiragana) => 'kana-ha',
                (bool)preg_match('/^[ま-も]/u', $hiragana) => 'kana-ma',
                (bool)preg_match('/^[や-よ]/u', $hiragana) => 'kana-ya',
                (bool)preg_match('/^[ら-ろ]/u', $hiragana) => 'kana-ra',
                (bool)preg_match('/^[わ-ん]/u', $hiragana) => 'kana-wa',
                default => '',
            };
            if ($key !== '') {
                $groups[$key]['names'][] = $name;
            } elseif (preg_match('/^[A-Za-z]/', $first)) {
                $letter = strtoupper($first);
                $groups['alpha']['letters'][$letter][] = $name;
            } else {
                $groups['other']['names'][] = $name;
            }
        }
        ksort($groups['alpha']['letters']);

        if (!is_dir($cacheDirectory)) {
            @mkdir($cacheDirectory, 0775, true);
        }
        if (is_dir($cacheDirectory) && is_writable($cacheDirectory)) {
            $encoded = json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $temporaryFile = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($temporaryFile, $encoded, LOCK_EX) !== false) {
                    @rename($temporaryFile, $cacheFile);
                } else {
                    @unlink($temporaryFile);
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('light directory cache failed: ' . $exception->getMessage());
    }

    return $groups;
}

function light_directory_group_has_names(array $group): bool
{
    if (($group['names'] ?? []) !== []) {
        return true;
    }
    foreach (($group['letters'] ?? []) as $names) {
        if (is_array($names) && $names !== []) {
            return true;
        }
    }
    return false;
}
