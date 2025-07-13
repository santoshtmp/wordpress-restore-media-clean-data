/**
 *
 */
jQuery(document).ready(function ($) {
    // Initialize TinyMCE for existing textareas
    // add new fields when clicking the add button

    // restore the missing media
    let download_media_list_start = $('ul#download_media_list').length;
    if (download_media_list_start) {
        let pageNumber = parseInt($('#page_number').val(), 10);
        if (pageNumber > 0) {
            let host_url = $('#host_url').val() || '';
            let retry_download = parseInt($('#retry_download').val() || '');
            let skip_unused_media = $('#skip_unused_media').is(':checked') ? 1 : 0;
            get_media_files(host_url, pageNumber, retry_download, skip_unused_media);
        }
    }
    // check used and unused media
    let check_used_unused_media_start = $('ul#check_used_unused_media_list').length;
    if (check_used_unused_media_start) {
        let pageNumber = parseInt($('#check_media_pnumber').val(), 10);
        if (pageNumber > 0) {
            check_used_unsed_media_files(pageNumber);
        }
    }

    // delete the posts before date
    let start_data_clean_list_start = $('ul#start_data_clean_list').length;
    if (start_data_clean_list_start) {
        let pageNumber = parseInt($('#old_date_page_number').val(), 10);
        if (pageNumber > 0) {
            let old_year_delete = $('#old_year_delete').val() || '2015';
            delete_old_post_data(pageNumber, old_year_delete);
        }

    }

    // delete the unsed data like draft, trash and other
    let clean_post_type_data_list_start = $('ul#clean_post_type_data_list').length;
    if (clean_post_type_data_list_start) {
        let pageNumber = parseInt($('#clean_post_type_page_number').val(), 10);
        if (pageNumber > 0) {
            clean_post_type_data_list(pageNumber);
        }

    }

    // Handle the form submission
    function get_media_files(host_url, page_number, retry_download, skip_unused_media) {
        let ajax = $.ajax({
            url: rmcdAjax.ajax_url,
            type: "POST",
            dataType: "json",
            data: {
                action: rmcdAjax.action_restoreMedia,
                _nonce: rmcdAjax.nonce,
                page_number: page_number,
                host_url: host_url,
                retry_download: retry_download,
                skip_unused_media: skip_unused_media
            },
        });

        ajax.done(function (response) {
            // $("#download_media_list").append(row);
            if (Array.isArray(response)) {
                response.forEach(function (item, index) {
                    const cleanItem = String(item).replace(/\n/g, '');
                    let row = `<li>${cleanItem}</li>`;
                    $("#download_media_list").append(row);
                });
            } else {
                console.warn("Expected array response, got:", response);
            }
            if (response.length > 0) {
                setTimeout(function () {
                    page_number++;
                    get_media_files(host_url, page_number, retry_download, skip_unused_media);
                }, 3000);
            }
        });
        ajax.fail(function (response, status, error) {
            console.error("Error message: ", status, error);
            // console.error("Error Text:", response.responseText);
            setTimeout(function () {
                get_media_files(host_url, page_number, retry_download, skip_unused_media);
            }, 3000);
        });
        ajax.always(function (response) {
            // console.log(response);

            if (response.length === 0) {
                $('#dmedia-loading-more').text('===== COMPLETED =====');
                return;
            }

        });
    }

    // Function to delete old post data
    function delete_old_post_data(page_number, old_year_delete) {

        let ajax = $.ajax({
            url: rmcdAjax.ajax_url,
            type: "POST",
            dataType: "json",
            data: {
                action: rmcdAjax.action_cleanByDate,
                _nonce: rmcdAjax.nonce,
                page_number: page_number,
                old_year_delete: old_year_delete, // Default to 2015 if not set
            },
        });

        ajax.done(function (response) {
            // $("#start_data_clean_list").append(row);
            if (Array.isArray(response)) {
                response.forEach(function (item, index) {
                    const cleanItem = String(item).replace(/\n/g, '');
                    let row = `<li>${cleanItem}</li>`;
                    $("#start_data_clean_list").append(row);
                });
            } else {
                console.warn("Expected array response, got:", response);
            }
            if (response.length > 0) {
                setTimeout(function () {
                    page_number++;
                    delete_old_post_data(page_number, old_year_delete)
                }, 3000);
            }
        });
        ajax.fail(function (response, status, error) {
            console.error("Error message:", status, error);
            // console.error("Error Text:", response.responseText);
            setTimeout(function () {
                delete_old_post_data(page_number, old_year_delete)
            }, 3000);
        });
        ajax.always(function (response) {
            // console.log(response);

            if (response.length === 0) {
                $('#data-clean-loading-more').text('COMPLETED - No more files to delete.');
                return;
            }

        });
    }

    // Function to delete old post data
    function clean_post_type_data_list(page_number = 1) {

        let ajax = $.ajax({
            url: rmcdAjax.ajax_url,
            type: "POST",
            dataType: "json",
            data: {
                action: rmcdAjax.action_cleanPostType,
                _nonce: rmcdAjax.nonce,
                page_number: page_number,
            },
        });

        ajax.done(function (response) {
            if (Array.isArray(response)) {
                response.forEach(function (item, index) {
                    const cleanItem = String(item).replace(/\n/g, '');
                    let row = `<li>${cleanItem}</li>`;
                    $("#clean_post_type_data_list").append(row);
                });
            } else {
                console.warn("Expected array response, got:", response);
            }
            if (response.length > 0) {
                setTimeout(function () {
                    page_number++;
                    clean_post_type_data_list(page_number)
                }, 3000);
            }
        });
        ajax.fail(function (response, status, error) {
            console.error("Error message:", status, error);
            // console.error("Error Text:", response.responseText);
            setTimeout(function () {
                clean_post_type_data_list(page_number)
            }, 3000);
        });
        ajax.always(function (response) {
            // console.log(response);
            if (response.length === 0) {
                $('#clean_post_type_data_more').text('COMPLETED - No more files to delete.');
                return;
            }

        });
    }

    // Function to check used and unused media files
    function check_used_unsed_media_files(page_number = 1) {
        let ajax = $.ajax({
            url: rmcdAjax.ajax_url,
            type: "POST",
            dataType: "json",
            data: {
                action: rmcdAjax.action_mediaCheck,
                _nonce: rmcdAjax.nonce,
                page_number: page_number,
                delete_unused_media: $('#delete_unused_media').is(':checked') ? 1 : 0
            },
        });

        ajax.done(function (response) {
            // $("#download_media_list").append(row);
            if (Array.isArray(response)) {
                response.forEach(function (item, index) {
                    // const cleanItem = String(item).replace(/\n/g, '');
                    const cleanItem = String(item).replace(/\n/g, '');
                    let row = `<li>${cleanItem}</li>`;
                    $("#check_used_unused_media_list").append(row);
                });
            } else {
                console.warn("Expected array response, got:", response);
            }
            if (response.length > 0) {
                setTimeout(function () {
                    page_number++;
                    check_used_unsed_media_files(page_number)
                }, 3000);
            }
        });
        ajax.fail(function (response, status, error) {
            console.error("Error message: ", status, error);
            // console.error("Error Text:", response.responseText);
            setTimeout(function () {
                check_used_unsed_media_files(page_number)
            }, 3000);
        });
        ajax.always(function (response) {
            // console.log(response);

            if (response.length === 0) {
                $('#check_used_unused_media_loading_more').text('COMPLETED - No more files to check. ');
                return;
            }

        });
    }
});
