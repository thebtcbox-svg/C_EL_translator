jQuery(document).ready(function($) {
    var pollingIntervals = {};
    var lastQueueProcess = 0;

    function pollJobStatus(jobId, progressBarId, statusId, resultId) {
        // Don't start duplicate intervals
        if (pollingIntervals[jobId]) return;

        pollingIntervals[jobId] = setInterval(function() {
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
                        stopPolling(jobId);
                        if (job.status === 'completed') {
                            if (resultId) $('#' + resultId).html('<span style="color: green;">Translation Complete!</span>');
                            // Delay reload slightly to show completion
                            setTimeout(function() { window.location.reload(); }, 1000);
                        } else {
                            if (resultId) $('#' + resultId).html('<span style="color: red;">Job Failed. Check logs.</span>');
                        }
                    } else if (['running', 'pending', 'retry'].indexOf(job.status) !== -1) {
                        // Ensure the queue is being processed, but throttled
                        triggerQueueProcessingThrottled();
                    }
                } else {
                    stopPolling(jobId);
                }
            }).fail(function() {
                stopPolling(jobId);
            });
        }, 4000); // Slightly longer interval to reduce load
    }

    function stopPolling(jobId) {
        if (pollingIntervals[jobId]) {
            clearInterval(pollingIntervals[jobId]);
            delete pollingIntervals[jobId];
        }
    }

    function stopAllPolling() {
        Object.keys(pollingIntervals).forEach(function(jobId) {
            stopPolling(jobId);
        });
    }

    function triggerQueueProcessingThrottled() {
        var now = Date.now();
        if (now - lastQueueProcess > 15000) { // Only trigger every 15 seconds max
            lastQueueProcess = now;
            $.post(ajaxurl, {
                action: 'cel_ai_process_queue_manual',
                nonce: celAiAdmin.jobStatusNonce
            });
        }
    }

    // Initialize polling for jobs that are actually active
    function initActivePolling() {
        $('#cel-ai-global-queue tr, .cel-ai-progress-container:visible').each(function() {
            var container = $(this);
            var statusTextEl = container.find('.cel-ai-status-text');
            if (statusTextEl.length === 0 && container.hasClass('cel-ai-status-text')) {
                statusTextEl = container;
            }
            
            var jobId = statusTextEl.data('job-id');
            var currentStatus = statusTextEl.text().toLowerCase();
            
            // Only poll if it looks like an active job
            if (jobId && (currentStatus.indexOf('pending') !== -1 || currentStatus.indexOf('running') !== -1 || currentStatus.indexOf('retry') !== -1 || currentStatus.indexOf('status:') !== -1)) {
                var pBarId = container.attr('id') ? container.attr('id') + ' .cel-ai-bar' : 'progress-global-' + jobId + ' .cel-ai-bar';
                var sId = container.attr('id') ? container.attr('id') + ' .cel-ai-status-text' : 'progress-global-' + jobId + ' .cel-ai-status-text';
                pollJobStatus(jobId, pBarId, sId);
            }
        });
    }

    initActivePolling();

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
                triggerQueueProcessingThrottled();
                window.location.reload();
            } else {
                $('#cel-ai-bulk-result').html('<span style="color: red;">' + response.data.message + '</span>');
            }
        });
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
                stopPolling(jobId);
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

    $(document).on('click', '.cel-ai-delete-btn', function() {
        var btn = $(this);
        var jobId = btn.data('job-id');
        if (confirm('Permanently delete this job from queue?')) {
            $.post(ajaxurl, {
                action: 'cel_ai_delete_job',
                job_id: jobId,
                nonce: celAiAdmin.jobStatusNonce
            }, function() {
                stopPolling(jobId);
                window.location.reload();
            });
        }
    });

    $('#cel-ai-clear-queue').on('click', function() {
        if (confirm('Permanently clear all jobs from queue? This cannot be undone.')) {
            stopAllPolling();
            $.post(ajaxurl, {
                action: 'cel_ai_clear_queue',
                nonce: celAiAdmin.jobStatusNonce
            }, function() {
                window.location.reload();
            });
        }
    });

    $('#cel-ai-process-now').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Processing...');
        lastQueueProcess = 0; // Force immediate trigger
        triggerQueueProcessingThrottled();
        setTimeout(function() {
            window.location.reload();
        }, 2000);
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
                triggerQueueProcessingThrottled();
                pollJobStatus(response.data.job_id, 'progress-' + lang + ' .cel-ai-bar', 'progress-' + lang + ' .cel-ai-status-text');
            } else {
                alert(response.data.message);
                btn.prop('disabled', false).text(originalText);
            }
        });
    });
});
