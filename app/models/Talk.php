<?php

class Talk extends Eloquent
{
	protected $table = 'talks';

	protected $guarded = array(
		'id'
	);

	public static $rules = array();

    public function author()
    {
        return $this->belongsTo('User', 'author_id');
    }

    public function scopeCurrentUserOnly($query)
	{
		$user = \Auth::user();

		return $query->where('author_id', Auth::user()->id);
	}
}
