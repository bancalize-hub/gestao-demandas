<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal de tempo real do CRM, isolado por empresa: só membros da empresa entram.
Broadcast::channel('crm.{companyId}', function ($user, $companyId) {
    return (int) $user->company_id === (int) $companyId;
});
