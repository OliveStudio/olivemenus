$(() => {
    const menuList = $('ol#menu-list');
    const menuForm = $('#menu-items-form');
    const placeholder = $('#menu-list-placeholder');
    const saveFeedback = $('<div>', {class: 'error', role: 'alert', tabindex: '-1'}).prependTo(menuForm);
    const deletedIds = new Set();
    const itemKind = $('#custom-menu-kind');
    const siteSelector = $('#custom-menu-site');
    const reorderActions = {
        up: 'Move up',
        down: 'Move down',
        indent: 'Indent',
        outdent: 'Outdent',
    };

    let nextId = 1;
    let saving = false;

    menuList.nestedSortable({
        handle: 'div',
        items: 'li',
        toleranceElement: '> div',
        isTree: true,
        forcePlaceholderSize: true,
        placeholder: 'placeholder',
        stop: updateReorderControls,
    });

    function field(label, input, id) {
        const heading = $('<div>', {class: 'heading'}).append($('<label>', {
            for: id,
            text: Craft.t('olivemenus', label),
        }));

        return $('<div>', {class: 'row field'}).append(
            heading,
            $('<div>', {class: 'input'}).append(input)
        );
    }

    function inputField(label, name, value = '', element = 'input') {
        const id = 'menu-field-' + nextId + '-' + name;
        const input = $('<' + element + '>', {id, name, class: 'text nicetext fullwidth'}).val(value);

        if (element === 'input') {
            input.attr('type', 'text');
        }

        return field(label, input, id);
    }

    function checkboxField(label, name, checked) {
        const id = 'menu-field-' + nextId + '-' + name;
        const input = $('<input>', {id, name, type: 'checkbox', class: 'checkbox'}).prop('checked', checked);

        return $('<div>', {class: 'field checkboxfield'}).append(
            input,
            $('<label>', {for: id, text: Craft.t('olivemenus', label)})
        );
    }

    function addReorderControls(item) {
        const controls = $('<div>', {
            class: 'btngroup',
            role: 'group',
            'aria-label': Craft.t('olivemenus', 'Move item'),
        });

        for (const [direction, label] of Object.entries(reorderActions)) {
            controls.append($('<button>', {
                type: 'button',
                class: 'btn small menu-reorder',
                'data-direction': direction,
                text: Craft.t('olivemenus', label),
            }));
        }

        item.children('div').find('.item-content > .inner').append(
            $('<div>', {class: 'field menu-reorder-controls'}).append(controls)
        );
    }

    function updateReorderControls() {
        menuList.find('.menu-reorder').each((_, button) => {
            const item = $(button).closest('li');
            const disabled = {
                up: !item.prev('li').length,
                down: !item.next('li').length,
                indent: !item.prev('li').length,
                outdent: item.parent('ol').is(menuList),
            };

            button.setAttribute('aria-disabled', disabled[button.dataset.direction] ? 'true' : 'false');
        });
    }

    function findField(item, name) {
        return item.children('div').find('[name="' + name + '"]');
    }

    function updateItemStatus(item) {
        const checkbox = findField(item, 'item-enabled').filter(':checkbox');
        item.children('div').find('.menu-item-status').toggle(checkbox.is(':not(:checked)'));
    }

    function setupItem(item) {
        const heading = item.children('div').find('.item-heading');
        heading.find('.menu-title').after($('<span>', {
            class: 'menu-item-status light',
            text: Craft.t('olivemenus', 'Hidden'),
        }));

        updateItemStatus(item);
        addReorderControls(item);
    }

    function addItem({name, customUrl = '', entryId = null, entryUrl = '', kind = 'link', targetSiteUid = ''}) {
        const item = $('<li>', {id: 'menu-item-' + nextId, class: 'mjs-nestedSortable-leaf'});
        const handle = $('<div>', {class: 'ui-sortable-handle'}).appendTo(item);
        const heading = $('<div>', {class: 'item-heading'}).appendTo(handle);
        const settingsId = 'menu-item-settings-' + nextId;

        heading.append(
            $('<button>', {
                type: 'button',
                class: 'settings-toggle',
                'aria-expanded': 'false',
                'aria-controls': settingsId,
                'aria-label': Craft.t('olivemenus', 'Item settings'),
            }),
            $('<span>', {class: 'menu-title', text: name}),
            $('<button>', {
                type: 'button',
                class: 'delete-menu btn small',
                text: Craft.t('olivemenus', 'Delete'),
            })
        );

        const details = $('<div>', {id: settingsId, class: 'item-content'}).appendTo(handle);
        const fields = $('<div>', {class: 'inner'}).appendTo(details);

        $('<input>', {type: 'hidden', name: 'kind', value: kind}).appendTo(fields);
        if (entryId !== null) {
            $('<input>', {type: 'hidden', name: 'item-entry-id'}).val(entryId).appendTo(fields);
        }

        const isLink = kind === 'link' || kind === 'site';
        fields.append(inputField(isLink ? 'Link text' : 'Label', 'item-name', name));
        if (kind === 'site') {
            const id = settingsId + '-site';
            const select = siteSelector.clone().attr({id, name: 'target-site-uid'}).val(targetSiteUid);
            fields.append(field('Destination site', $('<div>', {class: 'select'}).append(select), id));
        } else if (kind === 'link' && entryId === null) {
            fields.append(inputField('URL', 'custom-url', customUrl));
        }
        if (kind === 'group') {
            const sourceTemplate = document.getElementById('group-source-template');
            const sourceFields = sourceTemplate.content.cloneNode(true);
            for (const field of sourceFields.querySelectorAll('[id], [for], [aria-describedby]')) {
                for (const name of ['id', 'for', 'aria-describedby']) {
                    const value = field.getAttribute(name);
                    if (value) {
                        field.setAttribute(name, value.replaceAll('group-source-template', settingsId));
                    }
                }
            }
            fields.append(sourceFields);
        }
        if (kind !== 'link') {
            heading.find('.menu-title').after($('<span>', {
                class: 'light',
                text: itemKind.find('option').filter((_, option) => option.value === kind).text(),
            }));
        }

        fields.append(checkboxField('Show on the website', 'item-enabled', true));
        if (entryId !== null) {
            const suffix = inputField('URL suffix', 'url-suffix');
            const instructionsId = 'menu-field-' + nextId + '-suffix-help';
            suffix.find('input').attr({
                maxlength: 255,
                autocomplete: 'off',
                autocorrect: 'off',
                autocapitalize: 'none',
                spellcheck: 'false',
                'aria-describedby': instructionsId,
            });
            suffix.find('.heading').append($('<div>', {
                id: instructionsId,
                class: 'instructions',
                text: Craft.t('olivemenus', 'Add query parameters, an anchor, or both. Example: ?source=menu#overview'),
            }));
            fields.append(suffix);
            fields.append(checkboxField('Use the linked element title', 'item-use-element-title', false));
            fields.append(checkboxField('Hide when linked content is unavailable', 'item-hide-when-unavailable', true));
        }

        if (isLink) {
            const targetId = settingsId + '-target';
            const target = $('<select>', {id: targetId, name: 'target'}).append(
                $('<option>', {value: '_self', text: Craft.t('olivemenus', 'Same tab')}),
                $('<option>', {value: '_blank', text: Craft.t('olivemenus', 'New tab')})
            );
            fields.append(field('Open link in', $('<div>', {class: 'select'}).append(target), targetId));
        }

        fields.append(
            inputField('Link CSS class', 'class'),
            inputField('List item CSS class', 'class-parent'),
            inputField('Link attributes', 'data-json', '', 'textarea')
        );

        if (/^https?:\/\//i.test(entryUrl)) {
            fields.append(field('Original', $('<a>', {id: settingsId + '-original', href: entryUrl, target: '_blank', rel: 'noopener', text: name}), settingsId + '-original'));
        }

        setupItem(item);
        menuList.append(item);
        updateReorderControls();
        placeholder.hide();
        nextId++;
    }

    menuList.find('li').each((_, element) => {
        const item = $(element);
        const id = Number(element.id.replace('menu-item-', ''));
        nextId = Math.max(id + 1, nextId);
        setupItem(item);
    });
    updateReorderControls();

    menuList.on('click', '.settings-toggle', function () {
        const expanded = $(this).closest('li').toggleClass('active').hasClass('active');
        $(this).attr('aria-expanded', expanded ? 'true' : 'false');
    });

    menuList.on('change', '[name="item-enabled"]', function () {
        updateItemStatus($(this).closest('li'));
    });

    menuList.on('input', '[name="item-name"]', function () {
        $(this).closest('li').children('div').find('.menu-title').text(this.value);
    });

    menuList.on('click', '.delete-menu', function () {
        if (!confirm(Craft.t('olivemenus', 'Are you sure you want to delete this menu item?'))) {
            return;
        }

        const item = $(this).closest('li');
        const savedId = findField(item, 'item-id').val();
        if (savedId) {
            deletedIds.add(savedId);
        }

        const promoted = item.children('ol').children('li');
        const next = promoted.first().add(item.next('li')).add(item.prev('li')).first();
        const parent = item.parent('ol');
        promoted.insertBefore(item);
        item.remove();

        if (!parent.is(menuList) && !parent.children('li').length) {
            parent.remove();
        }

        next.children('div').find('.settings-toggle').trigger('focus');
        placeholder.toggle(menuList.children('li').length === 0);
        menuList.nestedSortable('refresh');
        updateReorderControls();
    });

    menuList.on('click', '.menu-reorder', function () {
        if (this.getAttribute('aria-disabled') === 'true') {
            return;
        }

        const item = $(this).closest('li');
        const parent = item.parent('ol');
        const previous = item.prev('li');
        const next = item.next('li');

        switch (this.dataset.direction) {
            case 'up':
                if (!previous.length) return;
                item.insertBefore(previous);
                break;
            case 'down':
                if (!next.length) return;
                item.insertAfter(next);
                break;
            case 'indent': {
                if (!previous.length) return;
                const children = previous.children('ol');
                item.appendTo(children.length ? children : $('<ol>').appendTo(previous));
                break;
            }
            case 'outdent':
                if (parent.is(menuList)) return;
                item.insertAfter(parent.closest('li'));
                if (!parent.children('li').length) {
                    parent.remove();
                }
                break;
        }

        menuList.nestedSortable('refresh');
        updateReorderControls();
        $(this).trigger('focus');
        Craft.cp.displayNotice(Craft.t('olivemenus', 'Menu item moved. Save to apply the changes.'));
    });

    $('[data-menu-element-type]').on('click', function () {
        const siteId = Number(this.dataset.siteId);

        Craft.createElementSelectorModal(this.dataset.menuElementType, {
            multiSelect: true,
            criteria: {siteId},
            defaultSiteId: siteId,
            siteIds: [siteId],
            showSiteMenu: false,
            showTitle: true,
            modalTitle: this.textContent.trim(),
            onSelect: (elements) => {
                for (const element of elements) {
                    addItem({name: element.label, entryId: element.id, entryUrl: element.url || ''});
                }
            },
        });
    });

    menuList.on('change', '[name="source-section-uid"]', function () {
        const manualOnly = this.value === '';
        $(this).closest('.inner').find('.group-source-options')
            .prop('hidden', manualOnly)
            .find('select, input').prop('disabled', manualOnly);
    });

    itemKind.on('change', () => {
        const kind = itemKind.val();
        $('#custom-menu-url-field').prop('hidden', kind !== 'link');
        $('#custom-menu-site-field').prop('hidden', kind !== 'site');
        siteSelector.prop('required', kind === 'site');
    });

    $('#menu-items-sidebar form.custom-url').on('submit', (event) => {
        event.preventDefault();

        const title = $('#custom-menu-title');
        const url = $('#custom-menu-url');
        const name = title.val().trim();

        title.toggleClass('error', !name);
        if (!name) {
            title.trigger('focus');
            return;
        }

        const kind = itemKind.val();
        addItem({
            name,
            kind,
            customUrl: kind === 'link' ? url.val() : '',
            targetSiteUid: kind === 'site' ? siteSelector.val() : '',
        });
        title.val('');
        url.val('');
    });

    menuForm.on('submit', (event) => {
        event.preventDefault();
        if (saving) {
            return;
        }

        const items = menuList.nestedSortable('toArray', {excludeRoot: true}).map(({id, parent_id}) => {
            const item = $('#menu-item-' + id);
            const data = {
                'item-id': {
                    db: findField(item, 'item-id').val() ?? null,
                    html: id,
                },
                'parent-id': parent_id,
                'name': findField(item, 'item-name').val(),
                'kind': findField(item, 'kind').val(),
                'target-site-uid': findField(item, 'target-site-uid').val(),
                'source-section-uid': findField(item, 'source-section-uid').val(),
                'source-sort': findField(item, 'source-sort').val(),
                'source-limit': findField(item, 'source-limit').val(),
                'entry-id': findField(item, 'item-entry-id').val() || undefined,
                'custom-url': findField(item, 'custom-url').val(),
                'url-suffix': findField(item, 'url-suffix').val(),
                'class': findField(item, 'class').val(),
                'class-parent': findField(item, 'class-parent').val(),
                'data-json': findField(item, 'data-json').val(),
                'target': findField(item, 'target').val(),
            };

            for (const flag of ['item-enabled', 'item-use-element-title', 'item-hide-when-unavailable']) {
                const control = findField(item, flag).filter(':checkbox');
                if (control.length) {
                    data[flag] = control.prop('checked');
                }
            }

            return data;
        });

        saving = true;
        saveFeedback.empty();
        const saveButton = $('[form="menu-items-form"][type="submit"]');
        saveButton.prop('disabled', true);

        Craft.sendActionRequest('POST', 'olivemenus/menu-items/save-menu-items', {
            headers: {Accept: 'application/json'},
            data: {
                'menu-id': menuForm.find('[name="menu-id"]').val(),
                'menu-items-deleted': Array.from(deletedIds).join(','),
                'menu-items-serialized': JSON.stringify(items),
            },
        }).then(({data}) => {
            if (typeof data.redirect !== 'string') {
                throw new Error('The save response is missing its redirect.');
            }

            window.location.assign(data.redirect);
        }).catch((error) => {
            const message = error.response?.data?.message;
            saving = false;
            saveButton.prop('disabled', false);
            saveFeedback.text(typeof message === 'string' ? message : Craft.t('olivemenus', 'Menu items could not be saved. Please try again.')).trigger('focus');
        });
    });
});
