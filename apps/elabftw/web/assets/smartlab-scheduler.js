(() => {
  const root = document.getElementById('smartlab-scheduler-root');
  if (!root) {
    return;
  }

  const portalView = root.dataset.portalView || 'student';
  const isStudentView = root.dataset.isStudent === '1';
  const csrfHtml = document.querySelector('input[name="csrf"]')?.outerHTML || '';
  const feedback = document.getElementById('smartlab-scheduler-feedback');
  const html = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char]));
  const asDate = value => new Date(String(value || '').replace(' ', 'T'));
  const pad = value => String(value).padStart(2, '0');
  const time = value => {
    const date = asDate(value);
    return Number.isNaN(date.getTime()) ? '' : `${pad(date.getHours())}:${pad(date.getMinutes())}`;
  };
  const dayTime = value => {
    const date = asDate(value);
    return Number.isNaN(date.getTime()) ? '' : `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
  };
  const shortDayTime = value => {
    const date = asDate(value);
    return Number.isNaN(date.getTime()) ? '' : `${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
  };

  const showFeedback = (message, ok = true) => {
    if (!feedback || !message) {
      return;
    }
    feedback.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`;
    feedback.textContent = message;
    window.setTimeout(() => feedback.classList.add('d-none'), 5000);
  };

  const resourceLabel = item => {
    const bits = [`${html(item.category_title || '资源')} · ${html(item.title || '未命名资源')}`];
    if (Number(item.book_max_minutes || 0) > 0) {
      bits.push(`单次≤${html(item.book_max_minutes)}分钟`);
    }
    if (String(item.book_can_overlap) === '0' || item.book_can_overlap === 0 || item.book_can_overlap === false) {
      bits.push('不允许重叠');
    }
    return bits.join(' · ');
  };

  const renderResourceOptions = resources => {
    const select = document.getElementById('smartlab-booking-item');
    if (!select) {
      return;
    }
    select.innerHTML = `<option value=''>请选择可预约资源</option>${resources.map(item => `<option value='${html(item.id)}'>${resourceLabel(item)}</option>`).join('')}`;
  };

  const renderResourceList = resources => {
    const target = document.getElementById('smartlab-resource-list');
    if (!target) {
      return;
    }
    if (!resources.length) {
      target.innerHTML = `<div class='alert alert-info mb-0'>还没有可预约资源。先在左侧创建一个仪器资源。</div>`;
      return;
    }
    target.innerHTML = resources.slice(0, 8).map(item => `
      <a href='database.php?mode=view&amp;id=${html(item.id)}&amp;smartlab_view=${html(portalView)}' class='list-group-item px-0'>
        <div class='font-weight-bold'>${html(item.title || '未命名资源')}</div>
        <div class='smallgray'>${resourceLabel(item)}${Number(item.booking_window_days || 0) > 0 ? ` · 可提前${html(item.booking_window_days)}天` : ''}</div>
      </a>`).join('');
  };

  const experimentOptions = experiments => `<option value=''>暂不关联</option>${experiments.map(experiment => `<option value='${html(experiment.id)}'>#${html(experiment.id)} · ${html(experiment.title || '未命名实验')}</option>`).join('')}`;

  const renderManageablePanel = (bookings, experiments) => {
    const target = document.getElementById('smartlab-manageable-panel');
    if (!target) {
      return;
    }
    if (!bookings.length) {
      target.innerHTML = `<div class='alert alert-info mb-0'>你当前没有可调整的未来预约。</div>`;
      return;
    }
    target.innerHTML = `
      <form method='post' action='scheduler.php?smartlab_view=${html(portalView)}' class='mb-3' id='smartlab-update-booking-form' data-smartlab-ajax-form='true'>
        ${csrfHtml}
        <input type='hidden' name='smartlab_action' value='update_booking' />
        <div class='form-group'>
          <label for='smartlab-edit-event'>选择预约</label>
          <select id='smartlab-edit-event' class='form-control' name='event_id' required>
            ${bookings.map(booking => `<option value='${html(booking.id)}'>#${html(booking.id)} · ${html(booking.item_title || '资源')} · ${shortDayTime(booking.start)}-${time(booking.end)}</option>`).join('')}
          </select>
        </div>
        <div class='form-row'>
          <div class='form-group col-md-6'><label for='smartlab-edit-date'>新日期</label><input id='smartlab-edit-date' class='form-control' type='date' name='booking_date' value='${new Date().toISOString().slice(0, 10)}' required /></div>
          <div class='form-group col-md-3'><label for='smartlab-edit-start'>开始</label><input id='smartlab-edit-start' class='form-control' type='time' name='start_time' value='09:00' required /></div>
          <div class='form-group col-md-3'><label for='smartlab-edit-end'>结束</label><input id='smartlab-edit-end' class='form-control' type='time' name='end_time' value='10:00' required /></div>
        </div>
        <div class='form-group'><label for='smartlab-edit-title'>新用途/备注</label><input id='smartlab-edit-title' class='form-control' name='title' placeholder='留空则清空备注' /></div>
        <div class='form-group'><label for='smartlab-edit-experiment'>关联实验记录</label><select id='smartlab-edit-experiment' class='form-control' name='experiment_id'>${experimentOptions(experiments)}</select></div>
        <button class='btn btn-outline-primary btn-sm' type='submit'>保存调整</button>
      </form>
      <div class='list-group list-group-flush' id='smartlab-manageable-bookings-list'>
        ${bookings.slice(0, 5).map(booking => `
          <div class='list-group-item px-0'>
            <div class='font-weight-bold'>${html(booking.item_title || '资源')}</div>
            <div class='smallgray'>${dayTime(booking.start)} - ${time(booking.end)} · ${html(booking.title || '无备注')}</div>
            ${booking.experiment_title ? `<div class='smallgray'>关联实验 #${html(booking.experiment)} · ${html(booking.experiment_title)}</div>` : ''}
            <form method='post' action='scheduler.php?smartlab_view=${html(portalView)}' class='mt-2' data-smartlab-ajax-form='true'>
              ${csrfHtml}
              <input type='hidden' name='smartlab_action' value='cancel_booking' />
              <input type='hidden' name='event_id' value='${html(booking.id)}' />
              <button class='btn btn-outline-danger btn-sm' type='submit'>取消预约</button>
            </form>
          </div>`).join('')}
      </div>`;
  };

  const renderDayBookings = (bookings, bookingDay) => {
    const target = document.getElementById('smartlab-day-bookings-list');
    const input = document.getElementById('smartlab-day-filter');
    if (input && bookingDay) {
      input.value = bookingDay;
    }
    if (!target) {
      return;
    }
    if (!bookings.length) {
      target.innerHTML = `<div class='alert alert-success mb-0'>这一天暂无预约占用。</div>`;
      return;
    }
    target.innerHTML = bookings.slice(0, 8).map(booking => `
      <div class='list-group-item px-0'>
        <div class='font-weight-bold'>${time(booking.start)}-${time(booking.end)} · ${html(booking.item_title || '资源')}</div>
        <div class='smallgray'>${html(booking.title || '无备注')}${!isStudentView ? ` · ${html(booking.booker_name || '未知成员')}` : ''}</div>
      </div>`).join('');
  };

  const renderUpcoming = data => {
    const target = document.getElementById('smartlab-upcoming-bookings-list');
    if (!target) {
      return;
    }
    const bookings = isStudentView ? (data.my_upcoming_bookings || []) : (data.upcoming_bookings || []);
    if (!bookings.length) {
      target.innerHTML = `<div class='alert alert-info mb-0'>${isStudentView ? '你当前没有未来预约。' : '当前没有未来预约。'}</div>`;
      return;
    }
    target.innerHTML = bookings.map(booking => `
      <div class='list-group-item px-0'>
        <div class='d-flex justify-content-between align-items-start flex-wrap'>
          <div class='mr-3'>
            <div class='smallgray text-uppercase'>${html(booking.category_title || '资源预约')}</div>
            <div class='font-weight-bold mt-1'>${html(booking.item_title || '资源')}</div>
            <div class='smallgray mt-1'>${html(booking.title || '无备注')}${booking.experiment_title ? ` · 关联实验 ${html(booking.experiment_title)}` : ''}${!isStudentView ? ` · 预约人 ${html(booking.booker_name || '未知成员')}` : ''}</div>
          </div>
          <div class='smallgray text-nowrap'>${dayTime(booking.start)}</div>
        </div>
      </div>`).join('');
  };

  const renderHotItems = items => {
    const target = document.getElementById('smartlab-hot-items-list');
    if (!target) {
      return;
    }
    if (!items.length) {
      target.innerHTML = `<div class='alert alert-info mb-0'>当前没有可预约资源。</div>`;
      return;
    }
    target.innerHTML = items.map(item => `
      <div class='list-group-item px-0'>
        <div class='smallgray text-uppercase'>${html(item.category_title || '可预约资源')}</div>
        <div class='font-weight-bold mt-1'>${html(item.title || '资源')}</div>
        <div class='smallgray mt-1'>${Number(item.booking_count || 0) > 0 ? `未来 30 天 ${html(item.booking_count)} 次预约${item.next_booking_at ? ` · 下次 ${dayTime(item.next_booking_at)}` : ''}` : '未来 30 天暂无预约'}</div>
      </div>`).join('');
  };

  const setText = (id, value) => {
    const target = document.getElementById(id);
    if (target) {
      target.textContent = value;
    }
  };

  const applyPayload = data => {
    setText('smartlab-overview-bookable', data.overview?.bookable_item_count || 0);
    setText('smartlab-overview-upcoming', data.overview?.upcoming_booking_count || 0);
    setText('smartlab-overview-active', isStudentView ? (data.overview?.my_upcoming_booking_count || 0) : (data.overview?.active_item_count || 0));
    setText('smartlab-overview-hot', (data.hot_items || []).filter(item => Number(item.booking_count || 0) > 0).length);
    renderResourceOptions(data.bookable_resources || []);
    renderResourceList(data.bookable_resources || []);
    renderManageablePanel(data.manageable_bookings || [], data.linkable_experiments || []);
    renderDayBookings(data.day_bookings || [], data.booking_day);
    renderUpcoming(data);
    renderHotItems(data.hot_items || []);
  };

  document.addEventListener('submit', async event => {
    const form = event.target.closest('form[data-smartlab-ajax-form]');
    if (!form) {
      return;
    }
    event.preventDefault();
    const formData = new FormData(form);
    if (formData.get('smartlab_action') === 'cancel_booking' && !window.confirm('确认取消这个预约吗？')) {
      return;
    }
    formData.set('smartlab_ajax', '1');
    if (!formData.get('booking_date') && document.getElementById('smartlab-day-filter')?.value) {
      formData.set('booking_date', document.getElementById('smartlab-day-filter').value);
    }
    const button = form.querySelector('button[type="submit"]');
    button && (button.disabled = true);
    try {
      const response = await fetch(form.action, {method: 'POST', body: formData, headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || '操作失败。');
      }
      applyPayload(data);
      showFeedback(data.message || '操作已完成。', true);
      if (formData.get('smartlab_action') === 'create_resource') {
        form.reset();
      }
      if (formData.get('smartlab_action') === 'create_booking') {
        const titleInput = form.querySelector('[name="title"]');
        if (titleInput) {
          titleInput.value = '';
        }
      }
    } catch (error) {
      showFeedback(error.message || '操作失败。', false);
    } finally {
      button && (button.disabled = false);
    }
  });

  document.addEventListener('submit', async event => {
    const form = event.target.closest('form[data-smartlab-day-form]');
    if (!form) {
      return;
    }
    event.preventDefault();
    const params = new URLSearchParams(new FormData(form));
    params.set('smartlab_ajax', '1');
    params.set('smartlab_view', portalView);
    try {
      const response = await fetch(`scheduler.php?${params.toString()}`, {headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || '查询失败。');
      }
      applyPayload(data);
    } catch (error) {
      showFeedback(error.message || '查询失败。', false);
    }
  });
})();
