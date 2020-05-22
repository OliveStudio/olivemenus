/**
 * Olivemenus plugin for Craft CMS
 *
 * Index Field JS
 *
 * @author    Olivestudio
 * @copyright Copyright (c) 2018 Olivestudio
 * @link      http://www.olivestudio.net/
 * @package   Olivemenus
 * @since     1.0.0
 */

$(document).ready(function() {
var menuList = $('#menu-list'),
    menuListItemsCount = menuList.find('tbody tr').length;

    $('#menu-list .delete').each(function(){

        var menu = $(this),
            menuParent = menu.parent().parent(),
            menuID = menuParent.attr('data-id'),
            menuName = menuParent.attr('data-name');

        $(this).on('click',function() {
            if (confirm(Craft.t('olivemenus', 'Are you sure you want to delete the "{menuName}" menu?', {menuName: menuName }))) {
                var data = {
                    menuID: menuID
                }
                // Add the CSRF Token
                data[csrfTokenName] = csrfTokenValue;

                $.post(siteUrl +'/olivemenus/delete-menu', data, null, 'json')
                    .done(function( data ) {
                        if (data.success) {
                            Craft.cp.displayNotice(Craft.t('olivemenus', 'Menu successfully deleted.'));
                            menuParent.remove();
                            
                            var menuListItems = menuList.find('tbody tr'),
                            menuListItemsCount = menuListItems.length;
                            
                            if ( menuListItemsCount == 0 )
                            {
                                menuList.remove();
                                $('#menu-none').removeClass('hidden');
                            }
                        }
                        else Craft.cp.displayError(Craft.t('olivemenus','Menu was not deleted. Please try again.'));
                    });
            }
        });
    });
});