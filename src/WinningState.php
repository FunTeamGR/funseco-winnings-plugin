<?php
namespace Funseco\Winnings;

use Illuminate\Database\Eloquent\Model;

/** Key/value row in nouse_winning_state (the bonus map counter). */
class WinningState extends Model
{
    protected $table = 'nouse_winning_state';
    protected $primaryKey = 'name';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
}
