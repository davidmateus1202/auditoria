<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallazgoAparicion extends Model
{
    protected $table = 'hallazgo_apariciones';

    protected $fillable = ['hallazgo_id', 'auditoria_id', 'texto_reportado', 'similitud', 'vinculo'];

    protected function casts(): array
    {
        return ['similitud' => 'float'];
    }

    public function hallazgo(): BelongsTo
    {
        return $this->belongsTo(Hallazgo::class);
    }

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class);
    }
}
