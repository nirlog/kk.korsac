<?php

declare(strict_types=1);

namespace KK\Korsac\Install;

use KK\Korsac\System\SystemException;

/** Fixed manifest only. No recursive overwrite/delete of application directories. */
final class PassportDeliveryFiles
{
    private readonly string $root;
    private readonly string $moduleRoot;

    public function __construct(string $documentRoot, ?string $moduleRoot=null)
    {
        $root=realpath($documentRoot);
        $module=realpath($moduleRoot ?? dirname(__DIR__, 2));
        if ($root===false || $module===false || !is_dir($root) || !is_dir($module)) throw new SystemException('passport_delivery_root_invalid');
        $this->root=rtrim($root, '/'); $this->moduleRoot=$module;
    }

    public function state(string $file): string
    {
        $source=$this->source($file);
        $path=$this->path($file);
        if ($path===null) return 'conflict';
        if (!file_exists($path)) return 'missing';
        if (!is_file($path)) return 'conflict';
        $content=@file_get_contents($path);
        if ($content===false) throw new SystemException('passport_delivery_read_failed');
        if (!str_contains($content, PassportDeliveryDefinition::OWNER)) return 'conflict';
        return $content===$source ? 'owned' : 'outdated';
    }

    public function install(string $file): void
    {
        $state=$this->state($file);
        if ($state==='conflict') throw new SystemException('passport_delivery_file_conflict');
        if ($state==='owned') return;
        $path=$this->path($file);
        $directory=dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) throw new SystemException('passport_delivery_write_failed');
        // Recheck after directory creation; refuse symlinks or foreign files even during repair.
        if ($this->path($file)===null || $this->state($file)==='conflict') throw new SystemException('passport_delivery_file_conflict');
        $source=$this->source($file);
        $temporary=@tempnam($directory, '.korsac-passport-');
        if ($temporary===false) throw new SystemException('passport_delivery_write_failed');
        try {
            if (@file_put_contents($temporary, $source)!==strlen($source) || !@chmod($temporary, 0644) || !@rename($temporary, $path)) throw new SystemException('passport_delivery_write_failed');
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    public function remove(string $file): void
    {
        $path=$this->path($file);
        if ($path===null || !is_file($path)) return;
        $content=@file_get_contents($path);
        if ($content===false) throw new SystemException('passport_delivery_read_failed');
        if (!str_contains($content, PassportDeliveryDefinition::OWNER)) return;
        if (!@unlink($path)) throw new SystemException('passport_delivery_write_failed');
        // Retain any directory containing application-owned files.
        foreach (['local/components/kk/korsac.passport/templates/.default', 'local/components/kk/korsac.passport/templates', 'local/components/kk/korsac.passport', 'passport'] as $directory) {
            @rmdir($this->root.'/'.$directory);
        }
    }

    private function source(string $file): string
    {
        $source=PassportDeliveryDefinition::files()[$file] ?? null;
        if ($source===null) throw new SystemException('passport_delivery_file_invalid');
        $path=$this->moduleRoot.'/'.$source;
        $content=is_file($path) && !is_link($path) ? @file_get_contents($path) : false;
        if ($content===false || !str_contains($content, PassportDeliveryDefinition::OWNER)) throw new SystemException('passport_delivery_source_invalid');
        return $content;
    }

    /** A symlink or non-directory parent is never evidence of module ownership. */
    private function path(string $file): ?string
    {
        if (!isset(PassportDeliveryDefinition::files()[$file])) throw new SystemException('passport_delivery_file_invalid');
        $path=$this->root; $parts=explode('/', $file);
        foreach ($parts as $index=>$part) {
            $path.='/'.$part;
            if (is_link($path) || ($index<count($parts)-1 && file_exists($path) && !is_dir($path))) return null;
        }
        return $path;
    }
}
