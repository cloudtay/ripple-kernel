use once_cell::sync::OnceCell;
use std::ffi::CStr;
use std::os::raw::c_char;
use std::path::PathBuf;
use std::sync::Mutex;
use std::{fs::OpenOptions, io::Write};

// 全局运行时目录
static RUNTIME_DIR: OnceCell<Mutex<PathBuf>> = OnceCell::new();

// 设置运行时目录
pub fn set_runtime_dir(dir: PathBuf) {
    let _ = RUNTIME_DIR.set(Mutex::new(dir));
}

// 获取FIFO路径
pub fn fifo_path() -> PathBuf {
    if let Some(lock) = RUNTIME_DIR.get() {
        if let Ok(g) = lock.lock() {
            let mut p = g.clone();
            p.push("bridge.fifo");
            return p;
        }
    }

    if let Ok(dir) = std::env::var("RIPPLE_RUNTIME_DIR") {
        let mut p = PathBuf::from(dir);
        p.push("bridge.fifo");
        return p;
    }

    let mut cwd = std::env::current_dir().unwrap_or_else(|_| PathBuf::from("../file"));
    cwd.push("bridge.fifo");
    cwd
}

// 初始化链接
#[no_mangle]
pub extern "C" fn link(runtime_dir: *const c_char) -> i32 {
    let path = if runtime_dir.is_null() {
        None
    } else {
        let c = unsafe { CStr::from_ptr(runtime_dir) };
        match c.to_str() {
            Ok(s) => Some(PathBuf::from(s)),
            Err(_) => None,
        }
    };

    let dir = path
        .unwrap_or_else(|| std::env::current_dir().unwrap_or_else(|_| PathBuf::from("../file")));
    set_runtime_dir(dir);
    0
}

// 通知协程完成
pub fn notify_ready(request_id: u32) {
    let fifo = fifo_path();
    if let Ok(mut w) = OpenOptions::new().write(true).open(&fifo) {
        let rid_be = request_id.to_be_bytes();
        let _ = w.write_all(&rid_be);
        let _ = w.flush();
    }
}
