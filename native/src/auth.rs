//! Re-read explicit credential files on token refresh, including key rotations.
use aws_config::profile::ProfileFileCredentialsProvider;
use aws_credential_types::provider::{ProvideCredentials, future};
use aws_runtime::env_config::file::{EnvConfigFileKind, EnvConfigFiles};
#[derive(Debug)]
pub struct RotatingProfile {
    pub file: String,
    pub profile: String,
}
impl ProvideCredentials for RotatingProfile {
    fn provide_credentials<'a>(&'a self) -> future::ProvideCredentials<'a>
    where
        Self: 'a,
    {
        future::ProvideCredentials::new(async move {
            let files = EnvConfigFiles::builder()
                .include_default_config_file(false)
                .include_default_credentials_file(false)
                .with_file(EnvConfigFileKind::Credentials, &self.file)
                .build();
            ProfileFileCredentialsProvider::builder()
                .profile_files(files)
                .profile_name(&self.profile)
                .build()
                .provide_credentials()
                .await
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn credentials_are_reloaded_after_atomic_rotation() {
        let file =
            std::env::temp_dir().join(format!("wp-dsql-credentials-test-{}", std::process::id()));
        let rotated = file.with_extension("replacement");
        let write = |path: &std::path::Path, key: &str| {
            std::fs::write(path,format!("[synthetic]\naws_access_key_id={key}\naws_secret_access_key=test-only-not-a-real-secret\n")).unwrap()
        };
        write(&file, "SYNTHETIC-FIRST");
        let provider = RotatingProfile {
            file: file.to_string_lossy().into(),
            profile: "synthetic".into(),
        };
        let rt = tokio::runtime::Runtime::new().unwrap();
        assert_eq!(
            rt.block_on(provider.provide_credentials())
                .unwrap()
                .access_key_id(),
            "SYNTHETIC-FIRST"
        );
        write(&rotated, "SYNTHETIC-ROTATED");
        std::fs::rename(&rotated, &file).unwrap();
        assert_eq!(
            rt.block_on(provider.provide_credentials())
                .unwrap()
                .access_key_id(),
            "SYNTHETIC-ROTATED"
        );
        std::fs::remove_file(file).unwrap();
    }
}
