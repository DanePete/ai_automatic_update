/**
 * @file
 * Handles real-time progress updates for analysis.
 */
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.aiUpgradeAssistantProgress = {
    attach: function (context, settings) {
      // Initialize progress bar
      const $progress = $('.analysis-progress', context);
      const $terminal = $('#terminal-output', context);
      let analysisInProgress = false;

      function updateProgress(data) {
        if (data.error) {
          $progress.find('.progress-message').text(Drupal.t('Error: ') + data.error);
          return;
        }

        const percent = Math.round((data.current / data.total) * 100);
        $progress.find('.progress-bar').css('width', percent + '%');
        $progress.find('.progress-message').text(data.message);
        
        // Update terminal with latest output
        if (data.terminal_output) {
          const $line = $('<div class="terminal-line"></div>').text(data.terminal_output);
          $terminal.append($line);
          $terminal.scrollTop($terminal[0].scrollHeight);
        }

        // Continue polling if analysis is still in progress
        if (data.status === 'in_progress') {
          setTimeout(checkProgress, 1000);
        }
        else if (data.status === 'complete') {
          analysisInProgress = false;
          $progress.removeClass('active');
          Drupal.behaviors.aiUpgradeAssistantProgress.refreshRecommendations();
        }
      }

      function checkProgress() {
        if (!analysisInProgress) {
          return;
        }

        $.ajax({
          url: Drupal.url('admin/reports/upgrade-assistant/progress'),
          success: updateProgress,
          error: function (xhr, status, error) {
            $progress.find('.progress-message').text(Drupal.t('Error checking progress'));
          }
        });
      }

      // Start analysis button handler
      $('.start-analysis', context).once('analysis-handler').on('click', function (e) {
        e.preventDefault();
        analysisInProgress = true;
        $progress.addClass('active');
        $terminal.empty();
        
        $.ajax({
          url: Drupal.url('admin/reports/upgrade-assistant/analyze'),
          success: function (response) {
            if (response.batch_token) {
              checkProgress();
            }
          }
        });
      });
    },

    refreshRecommendations: function () {
      const $recommendations = $('.recommendations-list');
      
      $.ajax({
        url: Drupal.url('admin/reports/upgrade-assistant/recommendations'),
        success: function (response) {
          $recommendations.html(response.content);
          Drupal.attachBehaviors($recommendations[0]);
        }
      });
    }
  };

})(jQuery, Drupal);
