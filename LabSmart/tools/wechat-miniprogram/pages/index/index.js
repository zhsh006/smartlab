const api = require('../../utils/api.js');
const app = getApp();

function parseChallenge(text) {
  if (!text) return '';
  let m = String(text).match(/[?&]c=([a-f0-9]{40})/);
  if (m) return m[1];
  m = String(text).match(/([a-f0-9]{40})/);
  return m ? m[1] : '';
}

Page({
  data: { loggedIn: false, user: null, email: '', password: '', busy: false, err: '', message: '' },
  onShow() {
    const t = wx.getStorageSync('sl_token');
    const u = wx.getStorageSync('sl_user');
    this.setData({ loggedIn: !!t, user: u || null });
  },
  onEmail(e) { this.setData({ email: e.detail.value }); },
  onPassword(e) { this.setData({ password: e.detail.value }); },
  login() {
    this.setData({ busy: true, err: '' });
    api.login(this.data.email, this.data.password, '微信小程序').then((d) => {
      if (!d.ok) { this.setData({ err: d.error, busy: false }); return; }
      wx.setStorageSync('sl_user', d.user); app.globalData.user = d.user;
      this.setData({ loggedIn: true, user: d.user, busy: false });
    }).catch((e) => this.setData({ err: '网络错误：' + (e && e.errMsg ? e.errMsg : JSON.stringify(e)), busy: false }));
  },
  scan() {
    if (!this.data.loggedIn) { this.setData({ err: '请先登录你的账号' }); return; }
    wx.scanCode({
      success: (res) => {
        const challenge = parseChallenge(res.result);
        if (!challenge) { this.setData({ err: '这不是有效的 LabSmart 登录二维码' }); return; }
        this.confirm(challenge);
      }
    });
  },
  confirm(challenge) {
    this.setData({ busy: true, err: '', message: '' });
    api.confirm(challenge, '微信小程序扫码').then((d) => {
      if (d.ok) { this.setData({ message: '已确认登录，请回到电脑端。', busy: false }); }
      else { this.setData({ err: d.error, busy: false }); }
    }).catch((e) => this.setData({ err: '网络错误：' + (e && e.errMsg ? e.errMsg : JSON.stringify(e)), busy: false }));
  },
  logout() {
    wx.removeStorageSync('sl_token'); wx.removeStorageSync('sl_user');
    app.globalData.token = ''; app.globalData.user = null;
    this.setData({ loggedIn: false, user: null });
  }
});
