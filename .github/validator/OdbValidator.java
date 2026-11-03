import java.io.File;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.ResultSet;
import java.sql.Statement;
import java.util.ArrayList;
import java.util.Enumeration;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;

/**
 * Opens the embedded HSQLDB database of each ODB file with HSQLDB 1.8 (the engine of LibreOffice Base)
 * and checks that every table created and every row inserted by its script is there: HSQLDB 1.8 only
 * logs the statements of the script it cannot replay, so they are counted against what it loaded.
 *
 * Usage: java -cp <hsqldb.jar> OdbValidator.java file.odb [file.odb ...]
 */
public class OdbValidator {
    private static final Pattern CREATE = Pattern.compile("^CREATE (?:MEMORY |CACHED |TEXT )?TABLE \"((?:[^\"]|\"\")+)\"");
    private static final Pattern INSERT = Pattern.compile("^INSERT INTO \"((?:[^\"]|\"\")+)\"");

    public static void main(String[] args) throws Exception {
        if (args.length == 0) {
            throw new IllegalArgumentException("No ODB file to validate");
        }
        Class.forName("org.hsqldb.jdbcDriver");
        for (String path : args) {
            System.out.println(path);
            Path directory = Files.createTempDirectory("odb");
            Map<String, Long> expected = new LinkedHashMap<>();
            try (ZipFile zip = new ZipFile(new File(path))) {
                ZipEntry mimetype = zip.getEntry("mimetype");
                String type = mimetype == null ? "" : new String(zip.getInputStream(mimetype).readAllBytes());
                if (!type.equals("application/vnd.oasis.opendocument.base")) {
                    throw new IllegalStateException("Unexpected mimetype: " + type);
                }
                int files = 0;
                for (Enumeration<? extends ZipEntry> entries = zip.entries(); entries.hasMoreElements();) {
                    ZipEntry entry = entries.nextElement();
                    if (entry.getName().startsWith("database/") && !entry.isDirectory()) {
                        try (InputStream input = zip.getInputStream(entry)) {
                            Files.copy(input, directory.resolve("db." + entry.getName().substring(9)), StandardCopyOption.REPLACE_EXISTING);
                        }
                        files++;
                    }
                }
                if (files == 0) {
                    throw new IllegalStateException("No embedded database");
                }
                for (String line : Files.readAllLines(directory.resolve("db.script"))) {
                    Matcher create = CREATE.matcher(line);
                    if (create.find()) {
                        expected.put(create.group(1).replace("\"\"", "\""), 0L);
                    }
                    Matcher insert = INSERT.matcher(line);
                    if (insert.find()) {
                        expected.merge(insert.group(1).replace("\"\"", "\""), 1L, Long::sum);
                    }
                }
            }
            String url = "jdbc:hsqldb:file:" + directory.resolve("db") + ";ifexists=true";
            try (Connection connection = DriverManager.getConnection(url, "SA", ""); Statement statement = connection.createStatement()) {
                List<String> tables = new ArrayList<>();
                try (ResultSet result = statement.executeQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.SYSTEM_TABLES WHERE TABLE_SCHEM = 'PUBLIC' AND TABLE_TYPE = 'TABLE'")) {
                    while (result.next()) {
                        tables.add(result.getString(1));
                    }
                }
                if (tables.isEmpty()) {
                    throw new IllegalStateException("No table");
                }
                if (!tables.containsAll(expected.keySet())) {
                    throw new IllegalStateException("Tables of the script " + expected.keySet() + ", loaded " + tables);
                }
                for (String table : tables) {
                    try (ResultSet result = statement.executeQuery("SELECT COUNT(*) FROM \"" + table.replace("\"", "\"\"") + "\"")) {
                        result.next();
                        long rows = result.getLong(1);
                        if (rows != expected.getOrDefault(table, 0L)) {
                            throw new IllegalStateException(table + ": " + expected.getOrDefault(table, 0L) + " rows in the script, " + rows + " loaded");
                        }
                        System.out.println("  table " + table + ": " + rows + " rows");
                    }
                }
                statement.execute("SHUTDOWN");
            }
        }
    }
}
