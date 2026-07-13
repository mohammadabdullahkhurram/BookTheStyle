<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use App\Support\ThemeRegistry;
use Database\Factories\WidgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One embeddable booking widget. A salon can run several (one per website /
 * location), each fully independent: its own name, its own branding JSON
 * (colors/logo/font — the same shape as salons.branding, merged OVER the
 * salon's as per-widget overrides), its own theme, and its own public embed
 * id. The public widget page resolves a widget by public_id within the
 * salon's slug — the id is public, never a secret.
 *
 * @property int $id
 * @property int $salon_id
 * @property string $name
 * @property string $type
 * @property string $public_id
 * @property array<string, mixed>|null $branding
 * @property string $theme
 */
class Widget extends Model
{
    /** @use HasFactory<WidgetFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = ['salon_id', 'name', 'type', 'public_id', 'branding', 'theme'];

    protected function casts(): array
    {
        return ['branding' => 'array'];
    }

    /** A fresh public embed identifier. */
    public static function newPublicId(): string
    {
        return Str::lower(Str::random(20));
    }

    /** The widget's theme, falling back to the default when it was retired. */
    public function themeKey(): string
    {
        return ThemeRegistry::selectable($this->theme, ThemeRegistry::SCOPE_WIDGET) ? $this->theme : 'marble';
    }
}
