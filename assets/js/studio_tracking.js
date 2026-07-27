/**
 * assets/js/studio_tracking.js
 * Motion Tracking workspace controller.
 * Manages tracking setup, target item attachments, and background job polling.
 */

(function () {
  'use strict';

  let els = {};
  let currentProjectState = null;
  let activeTrackingJobId = null;
  let trackingPollInterval = null;

  function initDOMRefs() {
    els = {
      mediaSelect:          document.getElementById('trackerMediaSelect'),
      targetSelect:         document.getElementById('trackerTargetSelect'),
      typeSelect:           document.getElementById('trackerTypeSelect'),
      startBtn:             document.getElementById('btnStartTracking'),
      configError:          document.getElementById('trackerConfigError'),
      progressWrapper:      document.getElementById('trackerProgressWrapper'),
      progressBar:          document.getElementById('trackerProgressBar'),
      progressText:         document.getElementById('trackerProgressText'),
      activeTracksList:     document.getElementById('activeTracksList')
    };
  }

  function populateDropdowns(state) {
    if (!els.mediaSelect || !els.targetSelect) return;

    const oldMediaVal = els.mediaSelect.value;
    const oldTargetVal = els.targetSelect.value;

    els.mediaSelect.innerHTML = '<option value="">-- Choose Source Video Clip --</option>';
    els.targetSelect.innerHTML = '<option value="">-- Choose Target Overlay --</option>';

    const items = state.items || [];

    // Sources: Video items
    const videos = items.filter(it => it.type === 'video');
    videos.forEach(vid => {
      const opt = document.createElement('option');
      opt.value = vid.id;
      opt.textContent = `${vid.name} (VIDEO - ${vid.duration.toFixed(1)}s)`;
      if (vid.id === oldMediaVal) opt.selected = true;
      els.mediaSelect.appendChild(opt);
    });

    // Targets: Text, Stickers, Graphics (div), Effects (overlay)
    const targets = items.filter(it => it.type === 'text' || it.type === 'caption' || it.type === 'sticker' || it.type === 'overlay');
    targets.forEach(tgt => {
      const opt = document.createElement('option');
      opt.value = tgt.id;
      opt.textContent = `${tgt.name} (${tgt.type.toUpperCase()})`;
      if (tgt.id === oldTargetVal) opt.selected = true;
      els.targetSelect.appendChild(opt);
    });
  }

  function handleStartTracking() {
    const mediaId = els.mediaSelect?.value;
    const targetId = els.targetSelect?.value;
    const type = els.typeSelect?.value || 'point';

    if (!mediaId || !targetId) {
      alert('Please select both a source video and a target overlay clip to track.');
      return;
    }

    const store = window.StudioProjectStore;
    if (!store) return;
    const state = store.getState();
    const clip = state.items.find(i => i.id === mediaId);
    if (!clip) return;

    els.startBtn.disabled = true;
    els.startBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Starting Track...';
    if (els.configError) els.configError.classList.add('d-none');
    if (els.progressWrapper) {
      els.progressWrapper.classList.remove('d-none');
      els.progressBar.style.width = '0%';
      els.progressText.textContent = 'Preparing video frame descriptors...';
    }

    fetch('backend/tracker_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'start_job', mediaId: mediaId, trackingType: type, duration: clip.duration, csrf_token: window.csrfToken || '' })
    })
    .then(res => res.json())
    .then(data => {
      if (!data.success) {
        els.startBtn.disabled = false;
        els.startBtn.innerHTML = '<i class="fa-solid fa-crosshairs me-1"></i> Start Motion Tracking';
        if (els.progressWrapper) els.progressWrapper.classList.add('d-none');

        if (data.error === 'TRACKER_UNCONFIGURED' || data.error === 'CREDENTIALS_MISSING') {
          if (els.configError) {
            els.configError.classList.remove('d-none');
            els.configError.querySelector('code').textContent = data.error === 'CREDENTIALS_MISSING' ? 'GOOGLE_APPLICATION_CREDENTIALS' : 'TRACKING_PROVIDER';
          }
        } else {
          alert(`Tracking request failed: ${data.message || 'Unknown error'}`);
        }
        return;
      }

      // Start Polling Job
      activeTrackingJobId = data.jobId;
      startStatusPolling(data.jobId, mediaId, targetId);
    })
    .catch(err => {
      els.startBtn.disabled = false;
      els.startBtn.innerHTML = '<i class="fa-solid fa-crosshairs me-1"></i> Start Motion Tracking';
      if (els.progressWrapper) els.progressWrapper.classList.add('d-none');
      alert(`Tracking connection failed: ${err.message || err}`);
    });
  }

  function startStatusPolling(jobId, mediaId, targetId) {
    if (trackingPollInterval) clearInterval(trackingPollInterval);

    trackingPollInterval = setInterval(() => {
      fetch('backend/tracker_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_status', jobId: jobId, csrf_token: window.csrfToken || '' })
      })
      .then(res => res.json())
      .then(data => {
        if (!data.success) {
          clearInterval(trackingPollInterval);
          resetTrackingUI();
          alert(`Job status fetch error: ${data.message || 'Unknown'}`);
          return;
        }

        const job = data.job;
        const progress = job.progress || 0.0;

        if (els.progressBar) {
          els.progressBar.style.width = `${progress}%`;
          els.progressText.textContent = `Analyzing frames: ${Math.round(progress)}% (${job.status.toUpperCase()})`;
        }

        if (job.status === 'completed') {
          clearInterval(trackingPollInterval);
          resetTrackingUI();

          const trajectory = job.result?.trajectory || [];
          if (trajectory.length === 0) {
            alert('Tracking job finished, but no motion path was detected.');
            return;
          }

          // Attach trajectory coordinates to selected target item
          const store = window.StudioProjectStore;
          if (store) {
            const st = store.getState();
            const targetItem = st.items.find(i => i.id === targetId);
            if (targetItem) {
              targetItem.tracking = {
                sourceItemId: mediaId,
                trackingType: job.trackingType,
                trajectory: trajectory
              };
              store.setState(st, true);
              alert(`🎉 Successfully attached motion path to ${targetItem.name}!`);
            }
          }
        } else if (job.status === 'failed') {
          clearInterval(trackingPollInterval);
          resetTrackingUI();
          alert('Motion tracking analysis failed.');
        }
      })
      .catch(err => {
        clearInterval(trackingPollInterval);
        resetTrackingUI();
        alert(`Status polling request failed: ${err.message || err}`);
      });
    }, 1000);
  }

  function resetTrackingUI() {
    if (els.startBtn) {
      els.startBtn.disabled = false;
      els.startBtn.innerHTML = '<i class="fa-solid fa-crosshairs me-1"></i> Start Motion Tracking';
    }
    if (els.progressWrapper) {
      els.progressWrapper.classList.add('d-none');
    }
    activeTrackingJobId = null;
  }

  function renderActiveTracks(state) {
    if (!els.activeTracksList) return;

    const attached = (state.items || []).filter(it => it.tracking && it.tracking.sourceItemId);
    if (attached.length === 0) {
      els.activeTracksList.innerHTML = '<div class="text-muted text-center py-2 italic small">No active tracking paths defined</div>';
      return;
    }

    const itemsMap = new Map(state.items.map(i => [i.id, i]));
    els.activeTracksList.innerHTML = attached.map(item => {
      const source = itemsMap.get(item.tracking.sourceItemId) || { name: 'Unknown Video' };
      return `
        <div class="d-flex justify-content-between align-items-center p-2 mb-1 bg-dark border border-secondary rounded">
          <div style="font-size:11px;">
            <div class="font-weight-bold text-cyber">${item.name}</div>
            <div class="text-secondary" style="font-size:10px;">Locks onto: ${source.name} (${item.tracking.trackingType})</div>
          </div>
          <button class="btn btn-sm btn-link text-danger p-0 btn-del-track" data-id="${item.id}" style="text-decoration:none;">
            <i class="fa-solid fa-trash-can"></i>
          </button>
        </div>
      `;
    }).join('');

    els.activeTracksList.querySelectorAll('.btn-del-track').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const store = window.StudioProjectStore;
        if (store) {
          const st = store.getState();
          const target = st.items.find(i => i.id === id);
          if (target) {
            delete target.tracking;
            store.setState(st, true);
          }
        }
      });
    });
  }

  function onStateChange(state) {
    currentProjectState = state;
    populateDropdowns(state);
    renderActiveTracks(state);
  }

  function init() {
    initDOMRefs();

    if (els.startBtn) {
      els.startBtn.addEventListener('click', handleStartTracking);
    }

    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      // Run initial rendering
      onStateChange(store.getState());
    }

    console.info('[StudioTracking] Workspace initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

})();
