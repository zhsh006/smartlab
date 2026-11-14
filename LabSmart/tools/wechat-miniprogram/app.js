// LabSmart Mini Program entry
App({
  globalData: {
    // NOTE: WeChat requires a real HTTPS domain that is registered & added to the
    // request whitelist (mp.weixin.qq.com -> 开发 -> 服务器域名). An internal IP or
    // self-signed cert will NOT work in the WeChat client.
    baseUrl: 'https://172.18.21.142:3159',
    token: '',
    user: null
  },
  onLaunch() {
    this.globalData.token = wx.getStorageSync('sl_token') || '';
    this.globalData.user = wx.getStorageSync('sl_user') || null;
  }
});
