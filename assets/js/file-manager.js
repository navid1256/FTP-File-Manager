    $(document).ready(function() {
      var config = window.FileManagerConfig || {};
      var csrfToken = config.csrfToken || '';
      var currentFolder = config.currentFolder || '';
      var canWriteCurrentFolder = !!config.canWriteCurrentFolder;
      var canSendFile = !!config.canSendFile;
      var selectedItems = [];
      function filterUsers(query) {
        var value = $.trim((query || '').toLowerCase());
        var $items = $('.folder-list .folder-item');
        var visibleCount = 0;

        $items.each(function() {
          var $item = $(this);
          var haystack = ($item.attr('data-search') || '').toLowerCase();
          var match = value === '' || haystack.indexOf(value) !== -1;
          $item.toggle(match);
          if (match) {
            visibleCount++;
          }
        });

        $('.no-match-users').toggle(visibleCount === 0 && $items.length > 0);
      }

      function getCheckboxItem($checkbox) {
        return {
          type: $checkbox.attr('data-type'),
          path: $checkbox.attr('data-path'),
          name: $checkbox.attr('data-name')
        };
      }

      function syncSelection() {
        selectedItems = [];
        $('.item-checkbox').each(function() {
          var $checkbox = $(this);
          var $row = $checkbox.closest('.file-item');
          var isChecked = $checkbox.is(':checked');

          $row.toggleClass('selected', isChecked);
          if (isChecked) {
            selectedItems.push(getCheckboxItem($checkbox));
          }
        });

        var selectableCount = $('.item-checkbox:not(:disabled)').length;
        var selectedCount = $('.item-checkbox:not(:disabled):checked').length;
        $('.select-all-items')
          .prop('checked', selectableCount > 0 && selectedCount === selectableCount)
          .prop('indeterminate', selectedCount > 0 && selectedCount < selectableCount);

        var hasSelection = selectedItems.length > 0 && canWriteCurrentFolder;
        $('#downloadBtn, #deleteBtn').prop('disabled', !hasSelection);
      }

      $('.item-checkbox').on('change', syncSelection);

      $('.select-all-items').on('change', function() {
        $('.item-checkbox:not(:disabled)').prop('checked', $(this).is(':checked'));
        syncSelection();
      });

      $('.file-item > i, .file-item > .file-name').on('click', function() {
        var $row = $(this).closest('.file-item');
        var openUrl = $row.attr('data-open-url');
        if ($row.attr('data-type') === 'dir' && openUrl) {
          window.location.href = openUrl;
        }
      });

      $('.main .nav .searchbox input').on('input', function() {
        filterUsers(this.value);
      });

      function escapeHtml(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function openDownloadLogModal(title) {
        $('#downloadLogTitle').text(title || 'Download Log');
        $('#downloadLogModal').removeAttr('hidden');
      }

      function closeDownloadLogModal() {
        $('#downloadLogModal').attr('hidden', 'hidden');
      }

      function renderDownloadLog(logs) {
        var $content = $('.download-log-content');
        $('.download-log-loading').hide();

        if (!logs || !logs.length) {
          $content.html('<div class="download-log-empty">No downloads have been logged yet.</div>');
          return;
        }

        var rows = logs.map(function(log) {
          var itemNames = (log.items || []).map(function(item) {
            return '<div class="download-log-item-name">' + escapeHtml(item.name || item.path || '-') + '</div>';
          }).join('');

          return '<tr>' +
            '<td>' + escapeHtml(log.downloaded_at || '-') + '</td>' +
            '<td>' + (itemNames || escapeHtml(log.download_name || '-')) + '</td>' +
            '<td>' + escapeHtml(log.download_name || '-') + '</td>' +
            '<td>' + escapeHtml(log.size_label || '-') + '</td>' +
            '</tr>';
        }).join('');

        $content.html(
          '<div class="download-log-table-wrap">' +
          '<table class="download-log-table">' +
          '<thead><tr><th>Date</th><th>File(s)</th><th>Downloaded as</th><th>Size</th></tr></thead>' +
          '<tbody>' + rows + '</tbody>' +
          '</table>' +
          '</div>'
        );
      }

      function renderSendFileLog(logs) {
        var $content = $('.download-log-content');
        $('.download-log-loading').hide();

        if (!logs || !logs.length) {
          $content.html('<div class="download-log-empty">No sent files have been logged yet.</div>');
          return;
        }

        var rows = logs.map(function(log) {
          var targetName = log.target_display_name || log.target_folder || '-';

          return '<tr>' +
            '<td>' + escapeHtml(log.sent_at || '-') + '</td>' +
            '<td>' + escapeHtml(log.file_name || '-') + '</td>' +
            '<td>' + escapeHtml(targetName) + '</td>' +
            '<td>' + escapeHtml(log.size_label || '-') + '</td>' +
            '</tr>';
        }).join('');

        $content.html(
          '<div class="download-log-table-wrap">' +
          '<table class="download-log-table">' +
          '<thead><tr><th>Date</th><th>File</th><th>Sent to</th><th>Size</th></tr></thead>' +
          '<tbody>' + rows + '</tbody>' +
          '</table>' +
          '</div>'
        );
      }

      $('#downloadLogBtn').click(function() {
        openDownloadLogModal('Download Log');
        $('.download-log-loading').show().text('Loading...');
        $('.download-log-content').empty();

        $.ajax({
          url: 'process/ajaxHandler.php',
          method: 'post',
          dataType: 'json',
          data: {
            action: 'getDownloadLog',
            csrf_token: csrfToken
          },
          success: function(response) {
            if (response && response.ok) {
              renderDownloadLog(response.data && response.data.logs ? response.data.logs : []);
            } else {
              $('.download-log-loading').text(response && response.message ? response.message : 'Error loading download log.');
            }
          },
          error: function() {
            $('.download-log-loading').text('Error loading download log.');
          }
        });
      });

      $('#sendFileLogBtn').click(function() {
        openDownloadLogModal('Send File Log');
        $('.download-log-loading').show().text('Loading...');
        $('.download-log-content').empty();

        $.ajax({
          url: 'process/ajaxHandler.php',
          method: 'post',
          dataType: 'json',
          data: {
            action: 'getSendFileLog',
            csrf_token: csrfToken
          },
          success: function(response) {
            if (response && response.ok) {
              renderSendFileLog(response.data && response.data.logs ? response.data.logs : []);
            } else {
              $('.download-log-loading').text(response && response.message ? response.message : 'Error loading send file log.');
            }
          },
          error: function() {
            $('.download-log-loading').text('Error loading send file log.');
          }
        });
      });

      $('[data-close-download-log]').on('click', closeDownloadLogModal);

      $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
          closeDownloadLogModal();
        }
      });

      function deleteItems(items) {
        if (!canWriteCurrentFolder) {
          alert('This folder is read-only for your account.');
          return false;
        }

        if (!items.length) {
          alert('Select at least one item first.');
          return false;
        }

        if (!confirm('Delete ' + items.length + ' selected item(s)?')) {
          return false;
        }

        $.ajax({
          url: 'process/ajaxHandler.php',
          method: 'post',
          dataType: 'json',
          data: {
            action: 'deleteItems',
            folder: currentFolder,
            items: items,
            csrf_token: csrfToken
          },
          success: function(response) {
            if (response && response.ok) {
              location.reload();
            } else {
              alert(response && response.message ? response.message : 'Error');
            }
          }
        });

        return true;
      }

      $('.delete-file').click(function(e) {
        e.preventDefault();
        e.stopPropagation();

        deleteItems([getCheckboxItem($(this))]);
      });

      $('#deleteBtn').click(function() {
        deleteItems(selectedItems);
      });

      $('#uploadBtn').click(function() {
        if (!currentFolder) {
          alert('Select a user first.');
          return;
        }
        if (!canSendFile) {
          alert('Send File is only available for other users.');
          return;
        }
        $('#uploadInput').trigger('click');
      });

      $('#uploadInput').on('change', function() {
        if (!this.files || !this.files.length) {
          return;
        }

        var formData = new FormData();
        formData.append('action', 'uploadFile');
        formData.append('csrf_token', csrfToken);
        formData.append('folder', currentFolder);
        formData.append('file', this.files[0]);

        $.ajax({
          url: 'process/ajaxHandler.php',
          method: 'post',
          data: formData,
          dataType: 'json',
          processData: false,
          contentType: false,
          success: function(response) {
            if (response && response.ok) {
              location.reload();
            } else {
              alert(response && response.message ? response.message : 'Error');
            }
          },
          complete: function() {
            $('#uploadInput').val('');
          }
        });
      });

      $('#downloadBtn').click(function() {
        if (!canWriteCurrentFolder) {
          alert('This folder is read-only for your account.');
          return;
        }

        if (!selectedItems.length) {
          alert('Select at least one item first.');
          return;
        }

        var $form = $('<form>', {
          method: 'post',
          action: 'process/download.php'
        });

        $form.append($('<input>', {
          type: 'hidden',
          name: 'csrf_token',
          value: csrfToken
        }));
        $form.append($('<input>', {
          type: 'hidden',
          name: 'folder',
          value: currentFolder
        }));

        $.each(selectedItems, function(index, item) {
          $form.append($('<input>', {
            type: 'hidden',
            name: 'items[' + index + '][path]',
            value: item.path
          }));
          $form.append($('<input>', {
            type: 'hidden',
            name: 'items[' + index + '][type]',
            value: item.type
          }));
          $form.append($('<input>', {
            type: 'hidden',
            name: 'items[' + index + '][name]',
            value: item.name
          }));
        });

        $('body').append($form);
        $form.trigger('submit');
        setTimeout(function() {
          $form.remove();
        }, 1000);
      });

      syncSelection();
      filterUsers($('.main .nav .searchbox input').val());
    });
