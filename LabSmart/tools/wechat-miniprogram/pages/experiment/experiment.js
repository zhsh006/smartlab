const api = require('../../utils/api.js');
Page({
  data: { exp: null, err: '' },
  onLoad(q) { this.id = q.id; },
  onShow() {
    api.experiment(this.id).then((d) => {
      if (!d.ok) { this.setData({ err: d.error }); return; }
      this.setData({ exp: d.experiment });
    }).catch(() => this.setData({ err: '网络错误' }));
  }
});
