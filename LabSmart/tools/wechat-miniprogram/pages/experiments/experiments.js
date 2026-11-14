const api = require('../../utils/api.js');
Page({
  data: { list: [], err: '', loading: true },
  onShow() {
    this.setData({ loading: true, err: '' });
    api.experiments().then((d) => {
      if (!d.ok) { this.setData({ err: d.error, loading: false }); return; }
      this.setData({ list: d.experiments, loading: false });
    }).catch(() => this.setData({ err: '网络错误', loading: false }));
  },
  open(e) {
    wx.navigateTo({ url: '/pages/experiment/experiment?id=' + e.currentTarget.dataset.id });
  }
});
