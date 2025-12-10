#!/usr/bin/php
<?php

foreach (['SONARR_KEY', 'SONARR_HOST'] as $required_env) {
    if (!getenv($required_env)) {
        printf("Missing env value '%s'\n", $required_env);
        exit(1);
    }
}

define('WAIT_TIME', 60 * 60 * 8);
define('HISTORY_PATH', '/data/history.json');
define('SONARR_KEY', trim(getenv('SONARR_KEY')));

if (!file_exists(HISTORY_PATH)) {
    echo "Creating history\n";
    file_put_contents(HISTORY_PATH, '{}');
}

echo "History at: " . realpath(HISTORY_PATH) . PHP_EOL;

$now = time();
$api_root = sprintf('https://%s/api/v3', trim(getenv('SONARR_HOST')));

$ch = curl_init($api_root .'/system/status');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . SONARR_KEY]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$status_data = curl_exec($ch);

if (curl_errno($ch)) {
    print "Error: " . curl_error($ch);
    exit(1);
}

$status_data = json_decode($status_data, true);
if ($now - strtotime($status_data['startTime']) < (60 * 30)) {
    echo "Waiting for Sonarr to be wake for at least 30 mins. \n";
    exit(0);
}


$history = json_decode(file_get_contents(HISTORY_PATH), true);


$updateHistory = function ($key, $id, $left, $title = '') use (&$history, $now) {
    $sid =  'id_' . $id;
    $left = (int) floor($left);

    if (!isset($history[$sid]) || $history[$sid]['left'] != $left) {
        $history[$sid] = [
            'id' => $id,
            'last_change' => $now,
            'left' => $left,
            'title' => $title,
        ];
    }
};

$removeHistory = function ($id) use (&$history) {
    foreach ($history as $key => $item) {
        if ($item['id'] == $id) {
            unset($history[$key]);
            return;
        }
    }
};

// Ensure a default entry
$updateHistory("default", -1, 0);

$ch = curl_init($api_root .'/queue?sortDirection=ascending&sortKey=added&includeUnknownSeriesItems=false&pageSize=100&page=1');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . SONARR_KEY]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$queue_data = curl_exec($ch);

$queue_data = json_decode($queue_data, true);
if (curl_errno($ch)) {
    print "Error: " . curl_error($ch);
    exit(1);
}

$valid_ids = [];
if ($queue_data['totalRecords'] > 0) {
    foreach ($queue_data['records'] as $item) {
        if ($item['protocol'] !== 'torrent') {
            continue;
        }

        $torrent_status = strtolower($item['status']);

        if ($torrent_status == 'delay') {
            continue;
        }

        // Stopped/paused downloads shouldn't be considered failed
        if ($torrent_status == 'paused') {
            continue;
        }

        $valid_ids[] = (string) $item['id'];

        if (
            ($torrent_status != 'queued' && ($item['sizeleft'] > 0 && $item['size'] > 1))
            || ($item['sizeleft'] == 0 && $item['size'] == 0) // Handle torrents don't ever start in qbittorrent
        ) {
            $updateHistory($item['downloadId'], $item['id'], $item['sizeleft'], $item['title']);
        } else {
            $removeHistory($item['id']);
        }
    }
}

$history = array_filter($history, function ($value) use ($valid_ids) {
    if (array_search((string) $value['id'], $valid_ids) === false) {
        return false;
    }

    return true;
});

uasort($history, function($a, $b) {
    if ($a['last_change'] == $b['last_change'])
        return 0;

    if ($a['last_change'] > $b['last_change'])
        return 1;

    return -1;
});

$removed_items = 5;
foreach ($history as $item) {
    if ($item['id'] > 0) {
        $name = $item['title'] ? sprintf(' (%s)', $item['title']) : '';

        if ($item['last_change'] < ($now - WAIT_TIME)) {
            echo "Blacklisting: " . $item['id'] . $name . PHP_EOL;

            $ch = curl_init($api_root .'/queue/' . $item['id']. '?blocklist=true&removeFromClient=true');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . SONARR_KEY]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $queue_data = curl_exec($ch);
            
            if (curl_errno($ch)) {
                print "Error: " . curl_error($ch) . PHP_EOL;
            }
            
            $removed_items--;
            $removeHistory($item['id']);
            
            if ($removed_items <= 0) {
                break;
            }
        } else {
            $difference = (int)(($item['last_change'] + WAIT_TIME - $now) / 60);
            if ($difference + 1 < WAIT_TIME / 60)
                echo sprintf("Giving %d minutes to %s\n", $difference, $item['title']);
        }
    }
}

// Ensure a default entry
$updateHistory("default", -1, 0);
file_put_contents(HISTORY_PATH, json_encode($history, JSON_PRETTY_PRINT));
