/**
 * @file
 * Handles diff view interactions.
 */
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.aiUpgradeAssistantDiff = {
    attach: function (context, settings) {
      $('.diff-file-header', context).once('diff-handler').on('click', function () {
        const $file = $(this).closest('.diff-file');
        $file.toggleClass('expanded');
      });

      // Initialize syntax highlighting if available
      if (window.hljs) {
        $('pre code', context).each(function(i, block) {
          hljs.highlightBlock(block);
        });
      }

      // Handle "Apply Changes" buttons
      $('.apply-changes', context).once('apply-handler').on('click', function (e) {
        e.preventDefault();
        const $button = $(this);
        const filePath = $button.data('file-path');
        
        $.ajax({
          url: Drupal.url('admin/reports/upgrade-assistant/apply-changes'),
          method: 'POST',
          data: {
            file_path: filePath
          },
          success: function (response) {
            if (response.success) {
              $button
                .text(Drupal.t('Changes Applied'))
                .prop('disabled', true)
                .addClass('success');
              
              // Update the terminal output
              const $terminal = $('#terminal-output');
              if ($terminal.length) {
                const $line = $('<div class="terminal-line"></div>')
                  .text(Drupal.t('Applied changes to @file', {'@file': filePath}));
                $terminal.append($line);
                $terminal.scrollTop($terminal[0].scrollHeight);
              }
            }
            else {
              alert(Drupal.t('Error applying changes: @error', 
                {'@error': response.error}));
            }
          },
          error: function () {
            alert(Drupal.t('Error applying changes. Please try again.'));
          }
        });
      });
    }
  };

})(jQuery, Drupal);
