$(() => {
    const menuList = $('#menu-list');

    menuList.on('click', '.delete', function () {
        const row = $(this).closest('tr');
        const menuName = row.attr('data-name');

        if (!confirm(Craft.t('olivemenus', 'Are you sure you want to delete the "{menuName}" menu?', {menuName}))) {
            return;
        }

        Craft.sendActionRequest('POST', 'olivemenus/menu/delete-menu', {
            headers: {Accept: 'application/json'},
            data: {menuID: row.attr('data-id')},
        }).then(({data}) => {
            if (!data.success) {
                throw new Error('Menu was not deleted.');
            }

            Craft.cp.displayNotice(Craft.t('olivemenus', 'Menu successfully deleted.'));
            row.remove();
            if (menuList.find('tbody tr').length === 0) {
                menuList.remove();
                $('#menu-none').removeClass('hidden');
            }
        }).catch(() => {
            Craft.cp.displayError(Craft.t('olivemenus', 'Menu was not deleted. Please try again.'));
        });
    });
});
