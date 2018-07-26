<?php
namespace Craft;

class OlivemenusVariable
{
    function getMenuHTML($handle, $config = array())
    {
        if ( $handle != '' )
        {
            return craft()->olivemenus->getMenuHTML($handle, $config);
        }
    }
}