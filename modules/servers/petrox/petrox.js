$(document).ajaxSuccess(function (e, jqxhr, settings) {
    if (typeof settings.data === 'string') {
        var data = settings.data.split('&').reduce(function (obj, param) {
            var parts = param.split('=');
            obj[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1]);
            return obj;
        }, {});

        // Check if the 'module' key is 'petrox'
        if (data.module === 'petrox') {
            if (settings.url.includes('configproducts.php')) {
                loadAdminConfigProducts();
            }
        }
    }
});

function loadSelect2(element, intSelect2) {
    const cNestIdElement = $(`#${element}`);
    if (cNestIdElement.length) {
        let tdParent = cNestIdElement.closest('td');
        const fieldareaElement = tdParent.next('td');
        if (fieldareaElement.length && fieldareaElement.hasClass('fieldarea')) {
            const selectElement = fieldareaElement.find('select');
            if (selectElement.length) {
                if (intSelect2) {
                    const selectName = selectElement.attr('name');
                    $(`[name="${selectName}"]`).select2();
                }
                return selectElement;
            }
        }
    }
    return false;
}

function filterEggs(eggElement, nestId) {
    eggElement.find('option').each(function () {
        const optionName = $(this).text();
        // Check if the optionValue contains the selectedValue in parentheses
        if (optionName.includes(`(${nestId})`)) {
            $(this).show(); // Show the option
        } else {
            $(this).attr('selected', false)
            $(this).hide(); // Hide the option if it doesn't match
        }
    });
    // Check if there's already a selected option within eggElement
    if (!eggElement.find('option:selected').length) {
        eggElement.find('option').each(function () {
            // Check if the option is visible
            if ($(this).css('display') !== 'none') {
                // Set the first visible option as selected
                $(this).prop("selected", true);
                return false; // Exit the loop after setting the first visible option
            }
        });
    }
}

function loadAdminConfigProducts() {
    const nestElement = loadSelect2('cNestId', true);
    const eggElement = loadSelect2('cEggId', false);
    let selectedValue = nestElement.find('option:selected').val();
    filterEggs(eggElement, selectedValue)
    nestElement.on('change', function () {
        selectedValue = $(this).val();
        filterEggs(eggElement, selectedValue)
    })
}

$(document).ready(function () {

    $('.save-action-petrox').on('click', function (e) {
        e.preventDefault();

        const button = $(this),
            formId = button.data('form-id'),
            form = $('#' + formId),
            originalButtonText = button.html();

        button.prop("disabled", true)
            .html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + originalButtonText);

        // Clear previous errors and success messages
        $('.error-messages, .success-message').removeClass('border-danger').hide();
        form.find('.border-danger').removeClass('border-danger');

        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: form.serialize(),
            success: function (response) {
                $('.success-message').html(response.message).fadeIn().delay(5000).fadeOut();
            },
            error: function (response) {
                const json = response.responseJSON;
                if (json.errors) {
                    json.errors.forEach(function (error) {
                        $('#' + error.input).addClass('border-danger')
                            .after('<div class="error-messages text-danger">' + error.message + '</div>');
                    });
                } else {
                    $('.error-message').html(json.message).fadeIn().delay(5000).fadeOut();
                }
            },
            complete: function () {
                button.html(originalButtonText).prop("disabled", false);
            }
        });
    });

    $(document).on('click', '.copy-text', function () {
        const text = $(this).data('clipboard-text');
        if (!text) alert('clipboard-text missing')
        navigator.clipboard.writeText(text);

        const changeTextId = $(this).data('id')
        if (!changeTextId) alert('data-id missing')
        const newText = $(this).data('new-text')
        if (!newText) alert('new-text missing')
        const findElement = $(`#${changeTextId}`)
        if (!findElement.length) alert(`element ${findElement} not found!`)

        let timer = 2000;
        if ($(this).data('timer')) {
            timer = $(this).data('timer');
        }

        if (findElement.is('input')) {
            const originalValue = findElement.val();
            findElement.val(newText);
            setTimeout(function () {
                findElement.val(originalValue);
            }, timer);
        } else {
            const originalContent = findElement.html();
            findElement.html(newText);
            setTimeout(function () {
                findElement.html(originalContent);
            }, timer);
        }
    })
});