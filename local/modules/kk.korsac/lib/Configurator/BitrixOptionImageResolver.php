<?php

declare(strict_types=1);

namespace KK\Korsac\Configurator;

final class BitrixOptionImageResolver implements OptionImageResolverInterface
{
    public function resolve(mixed $fileId): ?array
    {
        $id = filter_var($fileId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false) { return null; }
        $file = \CFile::GetFileArray((int)$id);
        $src = is_array($file) ? trim((string)($file['SRC'] ?? '')) : '';
        if ($src === '') { return null; }
        return ['src'=>$src, 'width'=>(int)($file['WIDTH'] ?? 0), 'height'=>(int)($file['HEIGHT'] ?? 0)];
    }
}
