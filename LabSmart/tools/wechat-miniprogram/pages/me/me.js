const api = require('../../utils/api.js');
const app = getApp();
Page({
  data: { user: null, err: '' },
  onShow() {
    api.me().then((d) => { if (d.ok) this.setData({ user: d.user }); else this.setData({ err: d.error }); })
      .catch(() => this.setData({ err: '网络错误' }));
  },
  logout() {
    wx.removeStorageSync('sl_token'); wx.removeStorageSync('sl_user');
    app.globalData.token = ''; app.globalData.user = null;
    wx.switchTab({ url: '/pages/index/index' });
  }
});
