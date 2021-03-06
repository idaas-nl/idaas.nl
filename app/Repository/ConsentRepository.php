<?php

namespace App\Repository;

use App\OAuthScope;

class ConsentRepository extends \ArieTimmerman\Laravel\AuthChain\Repository\ConsentRepository
{


    public function getDescriptions($scopes)
    {
                
        return OAuthScope::whereIn('name', $scopes)->get()->mapWithKeys(
            function ($item) {
                return [$item->name => $item->description];
            }
        )->all();

    }

}