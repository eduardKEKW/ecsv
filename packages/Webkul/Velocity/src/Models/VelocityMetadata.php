<?php

namespace Webkul\Velocity\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Velocity\Contracts\VelocityMetadata as VelocityMetadataContract;

class VelocityMetadata extends Model implements VelocityMetadataContract
{
    protected $table = 'velocity_meta_data';

    protected $guarded = [];

    public function homePageCategories () 
    {
        return $this->hasMany(CategoryProxy::modelClass())->where('front_visible', 1);
    }

    public function getLogoAttribute ()
    {
        $configKey = 'general.design.admin_logo.logo_image';

        return core()->getConfigData($configKey);
    }

}