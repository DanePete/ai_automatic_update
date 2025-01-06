(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.aiUpgradeAssistant = {
    attach: function (context, settings) {
      $('#check-status-button', context).once('upgrade-assistant').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $results = $('#upgrade-results-container');
        
        // Disable button and show loading
        $button.prop('disabled', true).val(Drupal.t('Checking...'));
        
        // Make AJAX call
        $.ajax({
          url: drupalSettings.aiUpgradeAssistant.checkStatusUrl,
          method: 'GET',
          success: function(response) {
            if (response.status === 'success') {
              var html = '<div class="upgrade-assistant-status">';
              
              // Add status items
              if (response.data.status_items.length > 0) {
                html += '<h3>' + Drupal.t('System Status') + '</h3><ul>';
                response.data.status_items.forEach(function(item) {
                  html += '<li class="status-' + item.type + '">' + item.message + '</li>';
                });
                html += '</ul>';
              } else {
                html += '<p>' + Drupal.t('Your site is up to date!') + '</p>';
              }
              
              // Add actions
              if (response.data.actions.length > 0) {
                html += '<h3>' + Drupal.t('Recommended Actions') + '</h3>';
                response.data.actions.forEach(function(action) {
                  html += '<div class="upgrade-action">' +
                    '<h4>' + action.title + '</h4>' +
                    '<pre>' + action.command + '</pre>' +
                    '<button class="button" onclick="return confirm(\'' + 
                    Drupal.t('Are you sure you want to run this command?') + 
                    '\')">' + Drupal.t('Run Command') + '</button>' +
                    '</div>';
                });
              }
              
              html += '</div>';
              $results.html(html);
            } else {
              $results.html('<div class="messages messages--error">' + 
                Drupal.t('Error checking status') + '</div>');
            }
          },
          error: function(xhr) {
            $results.html('<div class="messages messages--error">' + 
              Drupal.t('Error checking status: @error', {
                '@error': xhr.responseJSON?.message || Drupal.t('Unknown error')
              }) + '</div>');
          },
          complete: function() {
            // Re-enable button
            $button.prop('disabled', false).val(Drupal.t('Check Site Status'));
          }
        });
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
