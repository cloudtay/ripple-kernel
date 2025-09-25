// 文件操作模块
mod bridge;
mod ops;

// 初始化运行时目录
#[no_mangle]
pub extern "C" fn link(runtime_dir: *const std::os::raw::c_char) -> i32 {
    bridge::link(runtime_dir)
}

// 同步读取文件
#[no_mangle]
pub extern "C" fn file_get_contents(
    path: *const std::os::raw::c_char,
) -> *mut std::os::raw::c_char {
    ops::file_get_contents(path)
}

// 异步读取文件
#[no_mangle]
pub extern "C" fn file_get_contents_async(
    path: *const std::os::raw::c_char,
    request_id: u64,
) -> *mut std::os::raw::c_char {
    ops::file_get_contents_async(path, request_id)
}

// 发送文件到指定fd
#[no_mangle]
pub extern "C" fn process_file(client_fd: i32, path: *const std::os::raw::c_char) -> i32 {
    ops::process_file(client_fd, path)
}
