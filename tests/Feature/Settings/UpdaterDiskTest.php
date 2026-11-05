<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

test('updater disk is configured with public visibility', function () {
    $config = config('filesystems.disks.updater');

    expect($config['visibility'])->toBe('public')
        ->and($config['directory_visibility'])->toBe('public');
});

test('updater disk creates directories with 0755 permissions', function () {
    $tempRoot = storage_path('framework/testing/updater-disk-test-'.uniqid());

    config(['filesystems.disks.updater.root' => $tempRoot]);
    Storage::purge('updater');

    Storage::disk('updater')->put('subdir/test.json', '{}');

    $mode = fileperms($tempRoot.'/subdir') & 0777;

    File::deleteDirectory($tempRoot);
    Storage::purge('updater');

    expect($mode)->toBe(0755);
});
