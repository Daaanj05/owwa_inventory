<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RagEmbedding extends Model
{
    protected $table = 'rag_embeddings';

    protected $fillable = ['source', 'content', 'metadata', 'embedding'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'embedding' => 'array',
        ];
    }
}
