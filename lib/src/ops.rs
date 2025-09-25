use std::ffi::CStr;
use std::fs;
use std::fs::File;
use std::io::Read;
use std::os::raw::c_char;
use std::ptr;

use crate::bridge::notify_ready;

// 同步读取文件内容
#[no_mangle]
pub extern "C" fn file_get_contents(path: *const c_char) -> *mut c_char {
    if path.is_null() {
        return ptr::null_mut();
    }
    let c_path = unsafe { CStr::from_ptr(path) };
    let path_str = match c_path.to_str() {
        Ok(s) => s,
        Err(_) => return ptr::null_mut(),
    };

    // 坑FFI读也要\0x00截断
    let Ok(mut contents) = fs::read(path_str) else {
        return ptr::null_mut();
    };
    contents.push(0);
    let ptr_out = contents.as_mut_ptr();
    std::mem::forget(contents);
    ptr_out as *mut c_char
}

// 异步读取文件内容
#[no_mangle]
pub extern "C" fn file_get_contents_async(path: *const c_char, request_id: u64) -> *mut c_char {
    if path.is_null() {
        return ptr::null_mut();
    }
    let c_path = unsafe { CStr::from_ptr(path) };
    let path_str = match c_path.to_str() {
        Ok(s) => s.to_owned(),
        Err(_) => return ptr::null_mut(),
    };

    let (mut file, size) =
        match File::open(&path_str).and_then(|f| f.metadata().map(|m| (f, m.len() as usize))) {
            Ok(v) => v,
            Err(_) => return ptr::null_mut(),
        };

    let mut buf: Vec<u8> = Vec::with_capacity(size + 1);
    unsafe {
        buf.set_len(size + 1);
        *buf.as_mut_ptr().add(size) = 0;
    }
    let ptr_out = buf.as_mut_ptr();
    let ptr_addr = ptr_out as usize;

    std::mem::forget(buf);
    std::thread::spawn(move || {
        unsafe {
            let slice = std::slice::from_raw_parts_mut(ptr_addr as *mut u8, size);
            let _ = file.read_exact(slice);
        }
        notify_ready(request_id as u32);
    });

    ptr_out as *mut c_char
}

#[cfg(any(target_os = "macos", target_os = "ios"))]
fn sendfile_impl(client_fd: i32, path: &str) -> i32 {
    use libc::{off_t, sendfile};
    use std::fs::File;
    use std::io::Result as IoResult;
    use std::os::fd::AsRawFd;

    fn do_send(out_fd: i32, file: &File, file_len: usize) -> IoResult<i32> {
        let in_fd = file.as_raw_fd();
        let mut offset: off_t = 0;
        let mut remaining = file_len as off_t;
        while remaining > 0 {
            let mut len: off_t = remaining;
            let rc = unsafe {
                sendfile(
                    in_fd,
                    out_fd,
                    offset,
                    &mut len as *mut off_t,
                    std::ptr::null_mut::<libc::sf_hdtr>(),
                    0,
                )
            };
            if rc == -1 {
                let err = std::io::Error::last_os_error();
                match err.raw_os_error() {
                    // EAGAIN/EINTR 重试
                    Some(11) | Some(35) | Some(4) => continue,
                    _ => return Err(err),
                }
            }
            if len == 0 {
                break;
            }
            remaining -= len;
            offset += len as off_t;
        }
        Ok(0)
    }

    match File::open(path).and_then(|f| f.metadata().map(|m| (f, m.len() as usize))) {
        Ok((file, size)) => match do_send(client_fd, &file, size) {
            Ok(_) => 0,
            Err(_) => -1,
        },
        Err(_) => -1,
    }
}

#[cfg(target_os = "linux")]
fn sendfile_impl(client_fd: i32, path: &str) -> i32 {
    use libc::{off_t, sendfile, ssize_t};
    use std::fs::File;
    use std::os::fd::AsRawFd;

    match File::open(path).and_then(|f| f.metadata().map(|m| (f, m.len() as usize))) {
        Ok((file, mut remaining)) => {
            let in_fd = file.as_raw_fd();
            let mut offset: off_t = 0;
            while remaining > 0 {
                let rc = unsafe { sendfile(client_fd, in_fd, &mut offset, remaining) } as ssize_t;
                if rc < 0 {
                    let err = std::io::Error::last_os_error();
                    match err.raw_os_error() {
                        Some(11) | Some(4) => continue, // EAGAIN/EINTR
                        _ => return -1,
                    }
                } else if rc == 0 {
                    break;
                } else {
                    remaining -= rc as usize;
                }
            }
            0
        }
        Err(_) => -1,
    }
}

#[cfg(not(any(target_os = "linux", target_os = "macos", target_os = "ios")))]
fn sendfile_impl(client_fd: i32, path: &str) -> i32 {
    use std::fs::File;
    use std::io::{Read, Write};
    use std::os::fd::FromRawFd;

    let mut file = match File::open(path) {
        Ok(f) => f,
        Err(_) => return -1,
    };
    let mut out = unsafe { std::fs::File::from_raw_fd(client_fd) };
    let mut buf = [0u8; 1 << 16];
    loop {
        match file.read(&mut buf) {
            Ok(0) => break,
            Ok(n) => {
                if out.write_all(&buf[..n]).is_err() {
                    return -1;
                }
            }
            Err(_) => return -1,
        }
    }
    std::mem::forget(out);
    0
}

#[no_mangle]
pub extern "C" fn process_file(client_fd: i32, path: *const c_char) -> i32 {
    if path.is_null() {
        return -1;
    }
    let c_path = unsafe { CStr::from_ptr(path) };
    let path_str = match c_path.to_str() {
        Ok(s) => s,
        Err(_) => return -1,
    };
    sendfile_impl(client_fd, path_str)
}
