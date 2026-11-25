package mx.com.avba.caseta;

import java.text.Normalizer;
import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Deque;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.regex.PatternSyntaxException;

/**
 * Encuentra el NOMBRE y el CÓDIGO NUMÉRICO en el texto que el reconocimiento
 * del teléfono leyó de una tarjeta de identificación (gafete).
 *
 * No usa Android: recibe renglones de texto con su tamaño y posición y regresa
 * lo que encontró. Así se puede probar en la computadora y ajustar con reglas
 * (palabras clave, palabras que no son nombre, cuántos dígitos tiene el código
 * o un patrón propio) sin tocar la cámara.
 */
public final class AnalizadorTarjeta {

    private AnalizadorTarjeta() {}

    /** Un renglón leído: texto, posición (arriba, izquierda) y alto en píxeles. */
    public static final class Linea {
        public final String texto;
        public final int arriba, izquierda, alto;
        public Linea(String texto, int arriba, int izquierda, int alto) {
            this.texto = texto == null ? "" : texto;
            this.arriba = arriba; this.izquierda = izquierda; this.alto = Math.max(1, alto);
        }
    }

    /** Lo que se encontró en una lectura. */
    public static final class Lectura {
        public final String codigo, nombre;
        public final int puntaje;
        public Lectura(String codigo, String nombre, int puntaje) { this.codigo = codigo; this.nombre = nombre; this.puntaje = puntaje; }
        @Override public String toString() { return "codigo=" + codigo + " nombre=" + nombre + " puntaje=" + puntaje; }
    }

    /** Reglas de la tarjeta. Los valores por omisión sirven para un gafete con nombre y número de empleado. */
    public static final class Reglas {
        public int minDigitos = 4, maxDigitos = 12, longitud = 0;
        public Pattern patron = null;                  // si se da, el código es el grupo 1 (o todo) de este patrón
        public Set<String> claves = conjunto(
            "NO", "NUM", "NUMERO", "N", "ID", "EMPLEADO", "EMP", "FOLIO", "CODIGO", "COD", "CLAVE", "NOMINA",
            "FICHA", "GAFETE", "CREDENCIAL", "MATRICULA", "PERSONAL", "EXPEDIENTE", "NO.", "#");
        public Set<String> noNombre = conjunto(
            "QUANTUM", "ENERGY", "ENERGIA", "POWER", "GAFETE", "CREDENCIAL", "IDENTIFICACION", "EMPLEADO", "EMPLEADA",
            "NOMBRE", "PUESTO", "AREA", "DEPARTAMENTO", "DEPTO", "VIGENCIA", "VIGENTE", "VALIDO", "VALIDA", "VENCE", "EXPIRA",
            "CONTRATISTA", "VISITANTE", "PERSONAL", "FIRMA", "TITULAR", "NUMERO", "CODIGO", "FOLIO", "CLAVE", "TEL",
            "TELEFONO", "EMERGENCIA", "SANGRE", "TIPO", "NSS", "IMSS", "CURP", "RFC", "PLANTA", "SEGURIDAD", "ACCESO",
            "MEXICO", "SA", "CV", "S.A.", "C.V.", "INGENIERO", "INGENIERA", "ING", "TECNICO", "TECNICA", "SUPERVISOR",
            "SUPERVISORA", "OPERADOR", "OPERADORA", "GERENTE", "JEFE", "JEFA", "COORDINADOR", "COORDINADORA", "AUXILIAR",
            "ANALISTA", "ASISTENTE", "ESPECIALISTA", "MANTENIMIENTO", "PRODUCCION", "CALIDAD", "ALMACEN", "ADMINISTRACION",
            "OPERACIONES", "LOGISTICA", "AMBIENTAL", "ELECTRICO", "ELECTRICA", "MECANICO", "MECANICA", "INSTRUMENTACION",
            "PROYECTOS", "COMPRAS", "RECURSOS", "HUMANOS", "DIRECTOR", "DIRECTORA", "GENERAL", "SUBGERENTE", "PROPIEDAD",
            "EMPRESA", "DEVOLVER", "ENCUENTRA", "FAVOR", "INTRANSFERIBLE", "USO", "OBLIGATORIO", "EL");
        public Set<String> noCodigo = conjunto(
            "TEL", "TELEFONO", "CEL", "CELULAR", "EMERGENCIA", "NSS", "IMSS", "AFILIACION", "VIGENCIA", "VENCE",
            "EXPIRA", "EXPEDICION", "FECHA", "NACIMIENTO", "CP", "C.P.");
        /** Palabras que no deben contar para descartar un nombre aunque estén en noNombre (p. ej. «DE LA»). */
        public Set<String> particulas = conjunto("DE", "DEL", "LA", "LAS", "LOS", "Y", "MC", "MAC", "VAN", "VON", "DA", "DI", "SAN", "SANTA");

        public static Set<String> conjunto(String... p) {
            Set<String> s = new HashSet<>();
            for (String x : p) if (x != null && !x.trim().isEmpty()) s.add(sinAcentos(x.trim()).toUpperCase(Locale.ROOT));
            return s;
        }
        public void ponerPatron(String p) {
            if (p == null || p.trim().isEmpty()) { patron = null; return; }
            try { patron = Pattern.compile(p); } catch (PatternSyntaxException e) { patron = null; }
        }
    }

    private static final Locale ES_MX = Locale.forLanguageTag("es-MX");
    private static final Pattern MARCAS = Pattern.compile("\\p{M}+");
    private static final Pattern FECHA = Pattern.compile("\\b\\d{1,2}\\s?[/.\\-]\\s?\\d{1,2}\\s?[/.\\-]\\s?\\d{2,4}\\b");
    private static final Pattern MES = Pattern.compile("\\b(ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|SEPT|OCT|NOV|DIC)[A-Z]*\\b");
    /* Grupos de dígitos que no están pegados a letras (así no se toman pedazos de un CURP o un RFC) */
    private static final Pattern GRUPO = Pattern.compile("(?<![\\p{L}\\d])(\\d+(?:[ \\-]\\d+)*)(?![\\p{L}\\d])");
    private static final Pattern PALABRA = Pattern.compile("[\\p{L}][\\p{L}'\\-.]*");

    public static String sinAcentos(String t) {
        return MARCAS.matcher(Normalizer.normalize(t, Normalizer.Form.NFD)).replaceAll("");
    }

    /* El reconocimiento confunde letras con números dentro de un número: O→0, I/l→1, S→5, B→8, Z→2 */
    static String corregirDigitos(String linea) {
        String[] tok = linea.split("(?<=\\s)|(?=\\s)");
        StringBuilder sb = new StringBuilder();
        for (String t : tok) {
            int d = 0, l = 0;
            for (int i = 0; i < t.length(); i++) { char c = t.charAt(i); if (Character.isDigit(c)) d++; else if (Character.isLetter(c)) l++; }
            if (d >= 3 && d > l && l <= 2) {
                StringBuilder x = new StringBuilder();
                for (int i = 0; i < t.length(); i++) {
                    char c = t.charAt(i);
                    switch (c) {
                        case 'O': case 'o': case 'Q': x.append('0'); break;
                        case 'I': case 'l': case '|': case 'i': x.append('1'); break;
                        case 'S': case 's': x.append('5'); break;
                        case 'B': x.append('8'); break;
                        case 'Z': case 'z': x.append('2'); break;
                        default: x.append(c);
                    }
                }
                sb.append(x);
            } else sb.append(t);
        }
        return sb.toString();
    }

    static List<String> palabras(String t) {
        List<String> out = new ArrayList<>();
        for (String w : sinAcentos(t).toUpperCase(Locale.ROOT).split("[^A-Z0-9#.]+")) if (!w.isEmpty()) out.add(w.replaceAll("\\.+$", ""));
        return out;
    }
    private static boolean contieneAlguna(String t, Set<String> conj) {
        for (String w : palabras(t)) if (conj.contains(w) || conj.contains(w + ".")) return true;
        return t.contains("#") && conj.contains("#");
    }

    /** Analiza los renglones de UNA imagen. */
    public static Lectura analizar(List<Linea> lineas, Reglas r) {
        if (lineas == null || lineas.isEmpty()) return new Lectura(null, null, 0);
        List<Linea> orden = new ArrayList<>(lineas);
        orden.sort((a, b) -> a.arriba != b.arriba ? Integer.compare(a.arriba, b.arriba) : Integer.compare(a.izquierda, b.izquierda));
        int altoMax = 1;
        for (Linea l : orden) altoMax = Math.max(altoMax, l.alto);

        // ── Código ──
        String mejorCodigo = null; int mejorPuntaje = Integer.MIN_VALUE;
        for (int i = 0; i < orden.size(); i++) {
            Linea l = orden.get(i);
            String previo = i > 0 ? orden.get(i - 1).texto : "";
            List<String> cands = new ArrayList<>();
            if (r.patron != null) {
                Matcher m = r.patron.matcher(l.texto);
                while (m.find()) cands.add((m.groupCount() >= 1 && m.group(1) != null ? m.group(1) : m.group()).replaceAll("\\D", ""));
            } else {
                String t = corregirDigitos(l.texto);
                if (FECHA.matcher(t).find()) continue;                                   // es una fecha
                Matcher m = GRUPO.matcher(t);
                while (m.find()) {
                    String g = m.group(1);
                    String[] partes = g.split("[ \\-]");
                    boolean todosLargos = true;
                    for (String p : partes) if (p.length() < 3) { todosLargos = false; break; }
                    if (todosLargos) cands.add(g.replaceAll("\\D", ""));                  // «0012 3456» es un solo número
                    else for (String p : partes) cands.add(p);
                }
            }
            for (String c : cands) {
                int n = c.length();
                if (r.longitud > 0 ? n != r.longitud : (n < r.minDigitos || n > r.maxDigitos)) continue;
                int p = 10;
                boolean clave = contieneAlguna(l.texto, r.claves)
                        || (contieneAlguna(previo, r.claves) && soloNumero(l.texto, c));
                if (clave) p += 30;
                if (soloNumero(l.texto, c)) p += 12;
                if (contieneAlguna(l.texto, r.noCodigo) && !clave) p -= 40;
                String up = sinAcentos(l.texto).toUpperCase(Locale.ROOT);
                if (MES.matcher(up).find()) p -= 25;
                if (n == 4 && c.matches("(19|20)\\d\\d") && !clave) p -= 20;              // parece un año
                p += Math.round(10f * l.alto / altoMax);
                if (r.longitud > 0 || r.patron != null) p += 10;
                if (p > mejorPuntaje) { mejorPuntaje = p; mejorCodigo = c; }
            }
        }
        if (mejorCodigo != null && mejorPuntaje < 0) mejorCodigo = null;

        // ── Nombre ──
        String mejorNombre = null; int mejorN = Integer.MIN_VALUE;
        for (int i = 0; i < orden.size(); i++) {
            Linea l = orden.get(i);
            String t = l.texto.trim();
            boolean etiqueta = false;
            String tu = sinAcentos(t).toUpperCase(Locale.ROOT);
            if (tu.startsWith("NOMBRE")) {
                etiqueta = true;
                t = t.substring(Math.min(t.length(), 6)).replaceFirst("^[\\s:.\\-]+", "").trim();
                if (t.isEmpty() && i + 1 < orden.size()) t = orden.get(i + 1).texto.trim();
            }
            String limpio = nombreValido(t, r);
            if (limpio == null) continue;
            String previo = i > 0 ? sinAcentos(orden.get(i - 1).texto).toUpperCase(Locale.ROOT).trim() : "";
            int p = Math.round(40f * l.alto / altoMax);
            if (etiqueta || previo.startsWith("NOMBRE")) p += 30;
            if (t.equals(t.toUpperCase(Locale.ROOT))) p += 6;
            int nPal = limpio.split(" ").length;
            if (nPal >= 3) p += 6;
            if (p > mejorN) { mejorN = p; mejorNombre = limpio; }
        }
        return new Lectura(mejorCodigo, mejorNombre, mejorCodigo == null ? 0 : mejorPuntaje);
    }

    private static boolean soloNumero(String linea, String codigo) {
        int dig = 0, otros = 0;
        for (int i = 0; i < linea.length(); i++) {
            char c = linea.charAt(i);
            if (Character.isDigit(c)) dig++; else if (Character.isLetter(c)) otros++;
        }
        return dig >= codigo.length() && otros <= 2;
    }

    /** Regresa el nombre limpio («Juan Pérez López») o null si el renglón no parece un nombre. */
    static String nombreValido(String t, Reglas r) {
        if (t == null) return null;
        t = t.replaceAll("[|_*•·,;:]+", " ").replaceAll("\\s+", " ").trim();
        if (t.isEmpty() || t.matches(".*\\d.*")) return null;
        Matcher m = PALABRA.matcher(t);
        List<String> pal = new ArrayList<>();
        int letras = 0, fuera = 0;
        while (m.find()) { pal.add(m.group()); letras += m.group().replaceAll("[^\\p{L}]", "").length(); }
        for (String w : pal) fuera += w.length();
        int sinEspacios = t.replace(" ", "").length();
        if (sinEspacios - fuera > 1) return null;                     // trae símbolos raros
        if (pal.size() < 2 || pal.size() > 6 || letras < 6) return null;
        int importantes = 0;
        for (String w : pal) {
            String u = sinAcentos(w).toUpperCase(Locale.ROOT).replaceAll("[.'\\-]", "");
            if (r.particulas.contains(u)) continue;
            if (r.noNombre.contains(u) || r.noNombre.contains(u + ".")) return null;
            if (u.length() < 2) return null;
            importantes++;
        }
        if (importantes < 2) return null;
        // Nombre propio: cada palabra con mayúscula inicial (las partículas en minúscula)
        StringBuilder sb = new StringBuilder();
        for (String w : pal) {
            String u = sinAcentos(w).toUpperCase(Locale.ROOT).replaceAll("[.'\\-]", "");
            String x = w.toLowerCase(ES_MX);
            if (!r.particulas.contains(u) || sb.length() == 0) x = Character.toUpperCase(x.charAt(0)) + x.substring(1);
            if (sb.length() > 0) sb.append(' ');
            sb.append(x);
        }
        return sb.toString();
    }

    /**
     * Junta varias imágenes seguidas: un código se da por bueno cuando aparece
     * igual en «necesarias» de las últimas «ventana» lecturas. El nombre es el
     * que más veces se leyó junto con ese código.
     */
    public static final class Estabilizador {
        private final int necesarias, ventana;
        private final Deque<String> ultimas = new ArrayDeque<>();
        private final Map<String, Map<String, Integer>> nombres = new HashMap<>();
        public Estabilizador(int necesarias, int ventana) { this.necesarias = Math.max(1, necesarias); this.ventana = Math.max(this.necesarias, ventana); }

        /** Agrega una lectura; regresa la confirmada o null si todavía no hay suficientes. */
        public Lectura agregar(Lectura l) {
            String c = l == null ? null : l.codigo;
            ultimas.addLast(c == null ? "" : c);
            while (ultimas.size() > ventana) ultimas.removeFirst();
            if (c == null) return null;
            if (l.nombre != null) {
                Map<String, Integer> m = nombres.computeIfAbsent(c, k -> new HashMap<>());
                m.put(l.nombre, m.getOrDefault(l.nombre, 0) + 1);
            }
            int veces = 0;
            for (String x : ultimas) if (x.equals(c)) veces++;
            if (veces < necesarias) return null;
            return new Lectura(c, nombreDe(c), l.puntaje);
        }
        public String nombreDe(String c) {
            Map<String, Integer> m = nombres.get(c);
            if (m == null) return null;
            String mejor = null; int n = 0;
            for (Map.Entry<String, Integer> e : m.entrySet()) if (e.getValue() > n) { n = e.getValue(); mejor = e.getKey(); }
            return mejor;
        }
        public int vecesDe(String c) { int v = 0; for (String x : ultimas) if (x.equals(c)) v++; return v; }
    }

    /** Para pruebas y para el registro: el texto de los renglones en orden de lectura. */
    public static String unir(List<Linea> lineas) {
        List<Linea> orden = new ArrayList<>(lineas);
        orden.sort((a, b) -> a.arriba != b.arriba ? Integer.compare(a.arriba, b.arriba) : Integer.compare(a.izquierda, b.izquierda));
        StringBuilder sb = new StringBuilder();
        for (Linea l : orden) { if (sb.length() > 0) sb.append('\n'); sb.append(l.texto); }
        return sb.toString();
    }

    static List<String> lista(String... s) { return Arrays.asList(s); }
}
