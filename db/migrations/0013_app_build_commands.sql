-- Shell lines the deploy runs in the image build after composer, such as
-- npm ci and npm run build (Build_commands); '' runs none.
ALTER TABLE app ADD COLUMN build_commands TEXT NOT NULL DEFAULT '' AFTER php_extensions;
