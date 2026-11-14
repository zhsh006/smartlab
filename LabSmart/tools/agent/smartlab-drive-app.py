#!/usr/bin/env python3
"""SmartLab "我的空间" — 仪器电脑桌面同步应用 (Python 3.8+, only the standard library).

Sign in with your own SmartLab account; pick a local folder on this instrument PC;
sync uploads new/changed files to your personal space on the server, keeping the
folder structure (upload only; source files are never moved or deleted).

Self-test (no GUI):
    python smartlab-drive-app.py --selftest <server> <email> <password> <local_folder>
"""

import hashlib
import io
import json
import os
import queue
import ssl
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

DEFAULT_SERVER = 'https://172.18.21.142:3159'
APP_DIR_NAME = 'SmartLabDrive'
SKIP_SUFFIXES = ('.tmp', '.part', '.crdownload', '.partial', '.swp')


# --------------------------------------------------------------------------- config
def app_dir():
    base = os.environ.get('APPDATA') or os.path.expanduser('~')
    path = os.path.join(base, APP_DIR_NAME)
    os.makedirs(path, exist_ok=True)
    return path


CONFIG_PATH = os.path.join(app_dir(), 'config.json')


def load_config():
    try:
        with open(CONFIG_PATH, 'r', encoding='utf-8') as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return {}


def save_config(config):
    with open(CONFIG_PATH, 'w', encoding='utf-8') as handle:
        json.dump(config, handle, ensure_ascii=False, indent=1)


def sha256_of(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as handle:
        for chunk in iter(lambda: handle.read(1048576), b''):
            digest.update(chunk)
    return digest.hexdigest()


def encode_multipart(fields, files):
    boundary = uuid.uuid4().hex
    body = io.BytesIO()
    for key, value in fields.items():
        body.write(('--%s\r\n' % boundary).encode())
        body.write(('Content-Disposition: form-data; name="%s"\r\n\r\n' % key).encode('utf-8'))
        body.write(str(value).encode('utf-8'))
        body.write(b'\r\n')
    for name, path in files:
        body.write(('--%s\r\n' % boundary).encode())
        body.write(('Content-Disposition: form-data; name="files[]"; filename="%s"\r\n' % name).encode('utf-8'))
        body.write(b'Content-Type: application/octet-stream\r\n\r\n')
        with open(path, 'rb') as handle:
            while True:
                chunk = handle.read(262144)
                if not chunk:
                    break
                body.write(chunk)
        body.write(b'\r\n')
    body.write(('--%s--\r\n' % boundary).encode())
    return body.getvalue(), 'multipart/form-data; boundary=%s' % boundary


class ApiError(Exception):
    pass


class Api:
    def __init__(self, server, token=None, insecure=True):
        self.server = server.rstrip('/')
        self.token = token
        self.context = ssl._create_unverified_context() if insecure else ssl.create_default_context()

    def _request(self, url, data=None, content_type=None):
        headers = {'User-Agent': 'SmartLab-Drive/1.0'}
        if self.token:
            headers['X-SmartLab-Token'] = self.token
        if content_type:
            headers['Content-Type'] = content_type
        request = urllib.request.Request(url, data=data, headers=headers, method='POST' if data else 'GET')
        try:
            with urllib.request.urlopen(request, context=self.context, timeout=600) as response:
                return json.loads(response.read().decode('utf-8'))
        except urllib.error.HTTPError as error:
            try:
                return json.loads(error.read().decode('utf-8'))
            except Exception:
                raise ApiError('服务器返回 HTTP %s' % error.code)
        except urllib.error.URLError as error:
            raise ApiError('无法连接服务器：%s' % error)

    def _post(self, action, fields=None, files=None):
        fields = dict(fields or {})
        fields['action'] = action
        body, content_type = encode_multipart(fields, files or [])
        return self._request(self.server + '/smartlab-drive-api.php', body, content_type)

    def _get(self, action, params=None):
        query = urllib.parse.urlencode(dict(params or {}))
        url = self.server + '/smartlab-drive-api.php?action=' + action + (('&' + query) if query else '')
        return self._request(url)

    def login(self, email, password, device):
        result = self._post('login', {'email': email, 'password': password, 'device': device})
        if result.get('ok'):
            self.token = result['token']
        return result

    def whoami(self):
        return self._get('whoami')

    def list(self, path=''):
        return self._get('list', {'path': path})

    def index(self):
        return self._get('index')

    def mkdir(self, path):
        return self._post('mkdir', {'path': path})

    def upload(self, items):
        files = []
        manifest = []
        for rel, local in items:
            stat = os.stat(local)
            files.append((os.path.basename(rel), local))
            manifest.append({'path': rel, 'size': stat.st_size, 'sha256': sha256_of(local),
                             'mtime': int(stat.st_mtime), 'source_path': os.path.abspath(local)})
        return self._post('upload', {'manifest': json.dumps(manifest, ensure_ascii=False)}, files)


def collect_local(root):
    root = os.path.abspath(root)
    dirs, files = [], []
    for base, subdirs, names in os.walk(root):
        subdirs[:] = [d for d in subdirs if not d.startswith('.')]
        rel = os.path.relpath(base, root)
        if rel != '.':
            dirs.append(rel.replace('\\', '/'))
        for name in names:
            if name.startswith('.') or name.lower().endswith(SKIP_SUFFIXES):
                continue
            files.append(os.path.relpath(os.path.join(base, name), root).replace('\\', '/'))
    return dirs, files


# ----------------------------------------------------------------------------- GUI
def setup_style(root):
    """Polar-style flat theme for ttk widgets."""
    from tkinter import ttk
    style = ttk.Style(root)
    try:
        style.theme_use('clam')
    except Exception:
        pass
    ui = ('Microsoft YaHei UI', 10)
    style.configure('.', font=ui, background='#f3f5f8', foreground='#1e2733')
    style.configure('TFrame', background='#f3f5f8')
    style.configure('TLabel', background='#f3f5f8', foreground='#1e2733')
    style.configure('Muted.TLabel', foreground='#66727f')
    style.configure('Brand.TLabel', font=('Microsoft YaHei UI', 15, 'bold'), background='#0a66c2', foreground='#ffffff', padding=(9, 6))
    style.configure('Head.TLabel', font=('Microsoft YaHei UI', 13, 'bold'))
    style.configure('TEntry', fieldbackground='#ffffff', bordercolor='#cfd7e3', lightcolor='#cfd7e3', darkcolor='#cfd7e3', padding=6)
    style.configure('TButton', padding=(12, 6), background='#ffffff', bordercolor='#cfd7e3', focusthickness=0, foreground='#1e2733')
    style.map('TButton', background=[('active', '#eef2f7')])
    style.configure('Accent.TButton', background='#0a66c2', foreground='#ffffff', bordercolor='#0a66c2', padding=(14, 7))
    style.map('Accent.TButton', background=[('active', '#084f9b'), ('disabled', '#9db8d6')], foreground=[('disabled', '#eaf1f8')])
    style.configure('TLabelframe', background='#ffffff', bordercolor='#e2e7ee', relief='solid')
    style.configure('TLabelframe.Label', background='#f3f5f8', foreground='#5b6675', font=('Microsoft YaHei UI', 9, 'bold'))
    style.configure('Treeview', background='#ffffff', fieldbackground='#ffffff', foreground='#1e2733', rowheight=27, bordercolor='#e2e7ee')
    style.configure('Treeview.Heading', background='#f7f9fc', foreground='#5b6675', font=('Microsoft YaHei UI', 9, 'bold'), relief='flat', padding=6)
    style.map('Treeview.Heading', background=[('active', '#eef2f7')])


def run_gui():
    import tkinter as tk
    from tkinter import ttk, filedialog, messagebox, simpledialog
    from tkinter.scrolledtext import ScrolledText

    config = load_config()

    root = tk.Tk()
    root.title('SmartLab 我的空间')
    root.geometry('940x660')
    root.minsize(780, 540)
    setup_style(root)

    state = {'api': None, 'config': config, 'path': ''}

    container = ttk.Frame(root, padding=16)
    container.pack(fill='both', expand=True)

    # ---------------------------------------------------------------- login view
    login_frame = ttk.Frame(container)
    ttk.Label(login_frame, text='SmartLab 我的空间', font=('Microsoft YaHei UI', 18, 'bold')).grid(row=0, column=0, columnspan=2, pady=(10, 4), sticky='w')
    ttk.Label(login_frame, text='用你自己的账号登录，把本台仪器电脑的原始文件同步到你的空间。').grid(row=1, column=0, columnspan=2, sticky='w', pady=(0, 16))

    ttk.Label(login_frame, text='服务器地址').grid(row=2, column=0, sticky='w', pady=4)
    server_var = tk.StringVar(value=config.get('server', DEFAULT_SERVER))
    ttk.Entry(login_frame, textvariable=server_var, width=46).grid(row=2, column=1, sticky='we', pady=4)

    ttk.Label(login_frame, text='邮箱').grid(row=3, column=0, sticky='w', pady=4)
    email_var = tk.StringVar(value=config.get('email', ''))
    ttk.Entry(login_frame, textvariable=email_var, width=46).grid(row=3, column=1, sticky='we', pady=4)

    ttk.Label(login_frame, text='密码').grid(row=4, column=0, sticky='w', pady=4)
    password_var = tk.StringVar()
    password_entry = ttk.Entry(login_frame, textvariable=password_var, show='*', width=46)
    password_entry.grid(row=4, column=1, sticky='we', pady=4)

    ttk.Label(login_frame, text='设备名称').grid(row=5, column=0, sticky='w', pady=4)
    device_var = tk.StringVar(value=config.get('device', '仪器电脑'))
    ttk.Entry(login_frame, textvariable=device_var, width=46).grid(row=5, column=1, sticky='we', pady=4)

    login_status = ttk.Label(login_frame, text='', foreground='#b00')
    login_status.grid(row=6, column=0, columnspan=2, sticky='w', pady=(8, 4))

    login_frame.columnconfigure(1, weight=1)

    # ----------------------------------------------------------------- main view
    main_frame = ttk.Frame(container)

    header = ttk.Frame(main_frame)
    header.pack(fill='x', pady=(0, 10))
    ttk.Label(header, text='SL', style='Brand.TLabel').pack(side='left')
    ttk.Label(header, text='SmartLab 我的空间', style='Head.TLabel').pack(side='left', padx=10)
    header_right = ttk.Frame(header)
    header_right.pack(side='right')
    user_label = ttk.Label(header_right, text='', style='Head.TLabel')
    user_label.pack(side='left')
    server_label = ttk.Label(header_right, text='', style='Muted.TLabel')
    server_label.pack(side='left', padx=(10, 12))

    local_bar = ttk.LabelFrame(main_frame, text='本机同步文件夹', padding=10)
    local_bar.pack(fill='x', pady=(0, 10))
    local_var = tk.StringVar(value=config.get('local_root', ''))
    ttk.Entry(local_bar, textvariable=local_var).pack(side='left', fill='x', expand=True, padx=(0, 8))

    body = ttk.Frame(main_frame)
    body.pack(fill='both', expand=True)

    remote_bar = ttk.LabelFrame(body, text='我的空间（服务器）', padding=10)
    remote_bar.pack(side='left', fill='both', expand=True, padx=(0, 10))
    remote_head = ttk.Frame(remote_bar)
    remote_head.pack(fill='x')
    crumb = ttk.Label(remote_head, text='全部文件', foreground='#0a66c2')
    crumb.pack(side='left')
    remote_actions = ttk.Frame(remote_head)
    remote_actions.pack(side='right')
    columns = ('name', 'size', 'source')
    tree = ttk.Treeview(remote_bar, columns=columns, show='headings', height=12)
    tree.heading('name', text='名称')
    tree.heading('size', text='大小')
    tree.heading('source', text='来源设备')
    tree.column('name', width=320)
    tree.column('size', width=90, anchor='e')
    tree.column('source', width=160)
    tree.pack(fill='both', expand=True, pady=(8, 0))

    log_panel = ttk.LabelFrame(body, text='活动日志', padding=10)
    log_panel.pack(side='right', fill='both')
    log_box = ScrolledText(log_panel, width=34, height=8, state='disabled', font=('Consolas', 9))
    log_box.pack(fill='both', expand=True)

    messages = queue.Queue()

    def log(message):
        messages.put(message)

    def pump():
        try:
            while True:
                message = messages.get_nowait()
                log_box.configure(state='normal')
                log_box.insert('end', '[%s] %s\n' % (time.strftime('%H:%M:%S'), message))
                log_box.see('end')
                log_box.configure(state='disabled')
        except queue.Empty:
            pass
        root.after(200, pump)

    def refresh_remote():
        def worker():
            result = state['api'].list(state['path'])
            if not result.get('ok'):
                log('读取列表失败：%s' % result.get('error'))
                return
            space = result['space']

            def apply():
                crumb.config(text=' / '.join(item['name'] for item in space['breadcrumb']))
                tree.delete(*tree.get_children())
                for folder in space['folders']:
                    tree.insert('', 'end', iid='d:' + folder['path'], values=('📁 ' + folder['name'], '', ''), tags=('folder',))
                for file in space['files']:
                    tree.insert('', 'end', iid='f:' + str(file['path']), values=(file['original_name'], file['size_label'], file.get('source_device', '')), tags=('file',))
            root.after(0, apply)
        threading.Thread(target=worker, daemon=True).start()

    def browse(event=None):
        selection = tree.selection()
        if selection and selection[0].startswith('d:'):
            state['path'] = selection[0][2:]
            refresh_remote()

    def go_up():
        state['path'] = os.path.dirname(state['path']) if '/' in state['path'] else ''
        refresh_remote()

    def choose_folder():
        chosen = filedialog.askdirectory(title='选择本台仪器电脑的导出/数据文件夹')
        if chosen:
            local_var.set(chosen)
            state['config']['local_root'] = chosen
            save_config(state['config'])

    def open_folder():
        path = local_var.get().strip()
        if path and os.path.isdir(path):
            os.startfile(path)

    def new_remote_folder():
        name = simpledialog.askstring('新建文件夹', '文件夹名称：', parent=root)
        if not name:
            return
        target = (state['path'] + '/' + name) if state['path'] else name
        result = state['api'].mkdir(target)
        log('新建文件夹 %s %s' % (target, '成功' if result.get('ok') else '失败：' + str(result.get('error'))))
        refresh_remote()

    def do_sync():
        local_root = local_var.get().strip()
        if not local_root or not os.path.isdir(local_root):
            messagebox.showerror('提示', '请先选择本机同步文件夹。')
            return
        sync_btn.config(state='disabled')
        threading.Thread(target=sync_worker, args=(local_root,), daemon=True).start()

    def sync_worker(local_root):
        try:
            log('开始同步：%s' % local_root)
            result = state['api'].index()
            if not result.get('ok'):
                raise ApiError(str(result.get('error')))
            remote_files = {entry['path']: entry['sha256'] for entry in result['index']['files']}
            remote_dirs = set(result['index']['folders'])
            dirs, files = collect_local(local_root)
            for directory in dirs:
                if directory not in remote_dirs:
                    made = state['api'].mkdir(directory)
                    if not made.get('ok'):
                        log('建立文件夹失败 %s：%s' % (directory, made.get('error')))
            pending = []
            for rel in files:
                local_path = os.path.join(local_root, rel)
                if remote_files.get(rel) != sha256_of(local_path):
                    pending.append(rel)
            log('本地 %d 个文件，需上传 %d 个。' % (len(files), len(pending)))
            uploaded = 0
            batch = 20
            for start in range(0, len(pending), batch):
                chunk = pending[start:start + batch]
                items = [(rel, os.path.join(local_root, rel)) for rel in chunk]
                response = state['api'].upload(items)
                if not response.get('ok'):
                    raise ApiError(str(response.get('error')))
                uploaded += response.get('count', len(chunk))
                log('已上传 %d / %d' % (min(start + batch, len(pending)), len(pending)))
            log('同步完成：新增或更新 %d 个文件。' % uploaded)
            refresh_remote()
        except Exception as error:  # keep the UI alive
            log('同步失败：%s' % error)
        finally:
            root.after(0, lambda: sync_btn.config(state='normal'))

    def sign_out():
        try:
            state['api'].whoami()
        except Exception:
            pass
        state['config'].pop('token', None)
        save_config(state['config'])
        state['api'] = None
        show_login()

    ttk.Button(local_bar, text='选择文件夹', command=choose_folder).pack(side='left', padx=(0, 6))
    ttk.Button(local_bar, text='打开', command=open_folder).pack(side='left', padx=(0, 6))
    sync_btn = ttk.Button(local_bar, text='同步到我的空间', command=do_sync, style='Accent.TButton')
    sync_btn.pack(side='left')

    ttk.Button(header_right, text='断开登录', command=sign_out).pack(side='left')
    ttk.Button(remote_actions, text='新建文件夹', command=new_remote_folder).pack(side='right')
    ttk.Button(remote_actions, text='返回上级', command=go_up).pack(side='right', padx=(0, 6))
    ttk.Button(remote_actions, text='刷新', command=refresh_remote).pack(side='right', padx=(0, 6))
    tree.bind('<Double-1>', browse)

    def show_main():
        login_frame.pack_forget()
        main_frame.pack(fill='both', expand=True)
        server_label.config(text=state['config'].get('server', ''))
        user_label.config(text='👤 ' + str(state['config'].get('user_name', '')))
        refresh_remote()

    def show_login():
        main_frame.pack_forget()
        login_frame.pack(fill='both', expand=True)

    def do_login(event=None):
        server = server_var.get().strip()
        email = email_var.get().strip()
        password = password_var.get()
        device = device_var.get().strip() or '仪器电脑'
        if not server or not email or not password:
            login_status.config(text='请填写服务器、邮箱和密码。')
            return
        login_status.config(text='正在登录…')
        login_btn.config(state='disabled')
        api = Api(server)

        def worker():
            try:
                result = api.login(email, password, device)
                if not result.get('ok'):
                    raise ApiError(str(result.get('error')))
                state['api'] = api
                state['config'].update({'server': server, 'email': email, 'device': device,
                                        'token': api.token, 'user_name': result['user'].get('name', email)})
                save_config(state['config'])
                log('登录成功：%s' % state['config']['user_name'])
                root.after(0, show_main)
                root.after(0, lambda: login_status.config(text=''))
            except Exception as error:
                root.after(0, lambda: login_status.config(text=str(error)))
            finally:
                root.after(0, lambda: login_btn.config(state='normal'))

        threading.Thread(target=worker, daemon=True).start()

    login_btn = ttk.Button(login_frame, text='登录', command=do_login, style='Accent.TButton')
    login_btn.grid(row=7, column=0, columnspan=2, sticky='e', pady=(8, 0))
    password_entry.bind('<Return>', do_login)

    # resume session if a token is stored
    def try_resume():
        token = config.get('token')
        if not token:
            show_login()
            return
        api = Api(config.get('server', DEFAULT_SERVER), token)

        def worker():
            try:
                result = api.whoami()
                if result.get('ok'):
                    state['api'] = api
                    root.after(0, show_main)
                    return
            except Exception:
                pass
            root.after(0, show_login)

        threading.Thread(target=worker, daemon=True).start()

    pump()
    container.pack(fill='both', expand=True)
    try_resume()
    root.mainloop()


# ------------------------------------------------------------------------ selftest
def run_selftest(argv):
    if len(argv) < 5:
        print('usage: smartlab-drive-app.py --selftest <server> <email> <password> <local_folder>')
        return 2
    server, email, password, local_root = argv[1], argv[2], argv[3], argv[4]
    api = Api(server)
    login = api.login(email, password, 'SELFTEST-PC')
    print('login ok=%s user=%s' % (login.get('ok'), login.get('user', {}).get('name')))
    if not login.get('ok'):
        print('login error:', login.get('error'))
        return 1
    index = api.index()
    print('index ok=%s remote_files=%d remote_folders=%d' % (
        index.get('ok'), len(index['index']['files']), len(index['index']['folders'])))
    dirs, files = collect_local(local_root)
    remote = {entry['path']: entry['sha256'] for entry in index['index']['files']}
    pending = [rel for rel in files if remote.get(rel) != sha256_of(os.path.join(local_root, rel))]
    for directory in dirs:
        if directory not in set(index['index']['folders']):
            api.mkdir(directory)
    print('local dirs=%d files=%d pending=%d' % (len(dirs), len(files), len(pending)))
    if pending:
        result = api.upload([(rel, os.path.join(local_root, rel)) for rel in pending])
        print('upload ok=%s count=%s' % (result.get('ok'), result.get('count')))
    after = api.index()
    print('after sync remote_files=%d' % len(after['index']['files']))
    return 0


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--selftest':
        sys.exit(run_selftest(sys.argv[1:]))
    run_gui()
