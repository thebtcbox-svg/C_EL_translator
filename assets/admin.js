jQuery(document).ready(function($) {
    function pollJobStatus(jobId, progressBarId, statusId, resultId) {
        var interval = setInterval(function() {
            $.post(ajaxurl, {
                action: 'cel_ai_get_job_status',
                job_id: jobId,
                nonce: celAiAdmin.jobStatusNonce
            }, function(response) {
                if (response.success) {
                    var job = response.data;
                    var percent = job.progress ? job.progress.percent : 0;
                    if (progressBarId) $('#' + progressBarId).css('width', percent + '%');
                    if (statusId) $('#' + statusId).text(job.status.charAt(0).toUpperCase() + job.status.slice(1) + ' (' + percent + '%)');
                    
                    if (job.status === 'completed' || job.status === 'failed') {
                        clearInterval(interval);
                        if (job.status === 'completed') {
                            if (resultId) $('#' + resultId).html('<span style="color: green;">Translation Complete!</span>');
                            window.location.reload();
                        } else {
                            if (resultId) $('#' + resultId).html('<span style="color: red;">Job Failed. Check logs.</span>');
                        }
                    } else if (job.status === 'running' || job.status === 'pending' || job.status === 'retry') {
                        // Ensure the queue is being processed
                        triggerQueueProcessing();
                    }
                }
            });
        }, 3000);
    }

    function triggerQueueProcessing() {
        $.post(ajaxurl, {
            action: 'cel_ai_process_queue_manual',
            nonce: celAiAdmin.jobStatusNonce
        });
    }

    // Auto-start polling for all active jobs visible on settings page
    $('#cel-ai-global-queue tr').each(function() {
        var row = $(this);
        var jobId = row.find('.cel-ai-status-text').data('job-id');
        if (jobId) {
            pollJobStatus(jobId, 'progress-global-' + jobId + ' .cel-ai-bar', 'progress-global-' + jobId + ' .cel-ai-status-text');
        }
    });

    $('#cel-ai-check-updates').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Checking...');
        $.post(ajaxurl, {
            action: 'cel_ai_check_updates',
            nonce: celAiAdmin.updateNonce
        }, function(response) {
            btn.prop('disabled', false).text('Check for Updates from GitHub');
            if (response.success) {
                $('#cel-ai-update-result').html('<span style="color: green;">' + response.data.message + '</span>');
            } else {
                $('#cel-ai-update-result').html('<span style="color: red;">' + response.data.message + '</span>');
            }
        });
    });

    $('#cel-ai-test-connection').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true);
        $('#cel-ai-test-result').html('Testing...');

        $.post(ajaxurl, {
            action: 'cel_ai_test_connection',
            nonce: celAiAdmin.testNonce
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                $('#cel-ai-test-result').html('<span style="color: green;">' + response.data.message + '</span>');
            } else {
                $('#cel-ai-test-result').html('<span style="color: red;">' + response.data.message + '</span>');
            }
        });
    });

    $('#cel-ai-run-bulk').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Queuing...');

        $.post(ajaxurl, {
            action: 'cel_ai_trigger_translation',
            post_id: $('#cel_ai_bulk_post_id').val(),
            target_lang: $('#cel_ai_bulk_target_lang').val(),
            nonce: celAiAdmin.transNonce
        }, function(response) {
            btn.prop('disabled', false).text('Start Translation');
            if (response.success) {
                triggerQueueProcessing();
                window.location.reload();
            } else {
                $('#cel-ai-bulk-result').html('<span style="color: red;">' + response.data.message + '</span>');
            }
        });
    });

    // Sidebar auto-start polling
    $('.cel-ai-progress-container:visible').each(function() {
        var container = $(this);
        var jobId = container.find('.cel-ai-status-text').data('job-id');
        if (jobId) {
            pollJobStatus(jobId, container.attr('id') + ' .cel-ai-bar', container.attr('id') + ' .cel-ai-status-text');
        }
    });

    $(document).on('click', '.cel-ai-cancel-btn', function() {
        var btn = $(this);
        var jobId = btn.data('job-id');
        if (confirm('Cancel this job?')) {
            $.post(ajaxurl, {
                action: 'cel_ai_cancel_job',
                job_id: jobId,
                nonce: celAiAdmin.jobStatusNonce
            }, function() {
                window.location.reload();
            });
        }
    });

    $(document).on('click', '.cel-ai-retry-btn', function() {
        var btn = $(this);
        var jobId = btn.data('job-id');
        btn.prop('disabled', true).text('Retrying...');
        $.post(ajaxurl, {
            action: 'cel_ai_retry_job',
            job_id: jobId,
            nonce: celAiAdmin.jobStatusNonce
        }, function() {
            window.location.reload();
        });
    });

    $('.cel-ai-translate-btn').on('click', function() {
        var btn = $(this);
        var lang = btn.data('lang');
        var originalText = btn.text();
        btn.prop('disabled', true).text('Queuing...');
        $('#progress-' + lang).show();

        $.post(ajaxurl, {
            action: 'cel_ai_trigger_translation',
            post_id: btn.data('post-id'),
            target_lang: lang,
            nonce: celAiAdmin.transNonce
        }, function(response) {
            if (response.success) {
                triggerQueueProcessing();
                pollJobStatus(response.data.job_id, 'progress-' + lang + ' .cel-ai-bar', 'progress-' + lang + ' .cel-ai-status-text');
            } else {
                alert(response.data.message);
                btn.prop('disabled', false).text(originalText);
            }
        });
    });
});
