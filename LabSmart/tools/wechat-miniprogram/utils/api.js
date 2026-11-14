// Minimal API client for the LabSmart Mini Program.
const app = getApp();

function base() { return app.globalData.baseUrl.replace(/\/$/, ''); }
function token() { return app.globalData.token; }

function request(path, { method = 'GET', data = {}, auth = true } = {}) {
  return new Promise((resolve, reject) => {
    const header = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (auth && token()) header['X-SmartLab-Token'] = token();
    wx.request({
      url: base() + path,
      method,
      data,
      header,
      success: (res) => resolve(res.data),
      fail: (err) => reject(err)
    });
  });
}

module.exports = {
  // email+password -> app token (reuses the SmartLab drive API)
  login(email, password, device) {
    return request('/smartlab-drive-api.php', { method: 'POST', auth: false, data: { action: 'login', email, password, device } })
      .then((d) => { if (d.ok) { app.globalData.token = d.token; wx.setStorageSync('sl_token', d.token); } return d; });
  },
  // confirm a scan-login challenge (phone side)
  confirm(challenge, label) {
    return request('/smartlab-qr-api.php', { method: 'POST', data: { action: 'confirm', challenge, label } });
  },
  me() { return request('/smartlab-mobile-api.php?action=me'); },
  experiments() { return request('/smartlab-mobile-api.php?action=experiments'); },
  experiment(id) { return request('/smartlab-mobile-api.php?action=experiment&id=' + id); }
};
